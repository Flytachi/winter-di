<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Resolver;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Attribute\Lazy;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\ProxyInterface;
use Flytachi\Winter\DI\Exception\ContainerException;
use Flytachi\Winter\DI\Exception\NotFoundException;
use Flytachi\Winter\DI\ReflectionCache;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

final class ReflectionResolver
{
    /** @var array<string, list<array>> Cached parameter metadata keyed by class/method */
    private static array $cache = [];

    /** @var array<string, class-string> Concrete class name mapped to the identity it stands for */
    private static array $origins = [];

    public function resolve(string $class, Container $container, array $overrides = []): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException(
                "Class [{$class}] not found. Check the autoloader "
                . 'and that the package providing it is installed.'
            );
        }

        self::assertInstantiable($class);

        $params = $this->constructorParams($class);

        if (empty($params)) {
            return new $class();
        }

        $args = $this->buildArgs($params, $container, $overrides, self::originOf($class));
        return new $class(...$args);
    }

    public function call(callable|array $callable, Container $container, array $overrides = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $instance = is_string($target) ? $container->make($target) : $target;
            $ref    = ReflectionCache::method($instance::class, $method);
            $params = $this->methodParams($ref);
            $args   = $this->buildArgs($params, $container, $overrides, self::originOf($instance::class));
            return $ref->invoke($instance, ...$args);
        }

        $ref    = new ReflectionFunction(\Closure::fromCallable($callable));
        $params = $this->extractParams($ref->getParameters());
        $args   = $this->buildArgs($params, $container, $overrides);
        return $ref->invoke(...$args);
    }

    public function injectProperties(object $instance, Container $container): void
    {
        // consumer = the class being injected into → enables contextual() factories.
        // For a proxy that is the class it stands for, not the generated name.
        $consumer = self::originOf($instance::class);

        foreach ($this->injectionPlan($consumer) as $slot) {
            // #[Lazy] → inject a deferred proxy; otherwise resolve now.
            $slot['property']->setValue($instance, $slot['lazy']
                ? $this->lazyProxy($slot['type'], $container, $consumer)
                : $container->makeContextual($slot['type'], $consumer));
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Refuses a class the engine could only reject with a raw `Error`.
     *
     * `class_exists()` answers true for an abstract class and for an enum, so both walk
     * past the container's own check and die inside `new` — as `\Error`, which is not a
     * `ContainerExceptionInterface`. Code that dutifully wraps its resolution in
     * `catch (ContainerExceptionInterface)` never sees them, and PSR-11 says it should:
     * anything that goes wrong while retrieving an entry belongs to that interface.
     *
     * Checking beforehand rather than catching `\Error` around `new` is deliberate — a
     * `TypeError` thrown by the constructor's own body is the application's bug, and
     * relabelling it as a container failure would bury it.
     *
     * @param class-string $class
     * @throws ContainerException If the class cannot be instantiated at all.
     */
    private static function assertInstantiable(string $class): void
    {
        $ref = ReflectionCache::classOf($class);
        if ($ref->isInstantiable()) {
            return;
        }

        throw new ContainerException(match (true) {
            $ref->isAbstract() => "[{$class}] is abstract and cannot be instantiated. "
                . 'Bind it to a concrete class with bind() or singleton().',
            $ref->isEnum() => "[{$class}] is an enum and cannot be instantiated. "
                . 'Inject a case through a factory, or ask for the class that holds it.',
            default => "[{$class}] cannot be instantiated: its constructor is not public. "
                . 'Provide it through a factory — bind() with a closure, or #[Bean].',
        });
    }

    /**
     * Resolve the identity a concrete class stands for.
     *
     * Everything consumer-aware — contextual() factories, the injection plan —
     * must see the class the application wrote, not a generated subclass.
     *
     * @param class-string $class
     * @return class-string
     */
    private static function originOf(string $class): string
    {
        return self::$origins[$class] ??= is_subclass_of($class, ProxyInterface::class)
            ? $class::proxyTarget()
            : $class;
    }

    /**
     * Build — once per class — the list of properties that need injecting.
     *
     * The walk goes up the hierarchy instead of relying on
     * `ReflectionClass::getProperties()` alone, because that list omits private
     * properties declared in a parent. Without the walk an inherited
     * `#[Autowired] private` dependency is silently left null.
     *
     * A redeclared property is taken from the most derived class that declares
     * it, matching normal PHP resolution.
     *
     * @param class-string $class
     * @return list<array{property: ReflectionProperty, type: string, lazy: bool}>
     */
    private function injectionPlan(string $class): array
    {
        $key = 'props:' . $class;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $plan = [];
        $seen = [];

        for ($ref = ReflectionCache::classOf($class); $ref !== false; $ref = $ref->getParentClass()) {
            foreach ($ref->getProperties() as $property) {
                // Only declarations of this level; inherited ones are handled when we get there.
                if ($property->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }

                // Skip constructor-promoted properties — already set via constructor injection
                if ($property->isPromoted() || isset($seen[$property->getName()])) {
                    continue;
                }

                $injectAttrs    = $property->getAttributes(Inject::class);
                $autowiredAttrs = $property->getAttributes(Autowired::class);
                $lazyAttrs      = $property->getAttributes(Lazy::class);
                if (empty($injectAttrs) && empty($autowiredAttrs) && empty($lazyAttrs)) {
                    continue;
                }

                $seen[$property->getName()] = true;

                $id = !empty($injectAttrs)
                    ? $injectAttrs[0]->newInstance()->id
                    : null;
                $type = $id ?? $property->getType()?->getName();

                if ($type === null) {
                    throw new ContainerException(
                        "Cannot inject property [{$property->getName()}] — no type and no #[Inject] id."
                    );
                }

                $plan[] = [
                    'property' => $property,
                    'type'     => $type,
                    'lazy'     => !empty($lazyAttrs),
                ];
            }
        }

        return self::$cache[$key] = $plan;
    }

    private function constructorParams(string $class): array
    {
        $key = 'ctor:' . $class;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $constructor = ReflectionCache::classOf($class)->getConstructor();

        return self::$cache[$key] = $constructor
            ? $this->extractParams($constructor->getParameters())
            : [];
    }

    private function methodParams(ReflectionMethod $method): array
    {
        $key = 'method:' . $method->getDeclaringClass()->getName() . '::' . $method->getName();
        return self::$cache[$key] ??= $this->extractParams($method->getParameters());
    }

    /** @param ReflectionParameter[] $parameters */
    private function extractParams(array $parameters): array
    {
        $result = [];
        foreach ($parameters as $param) {
            $injectAttr    = $param->getAttributes(Inject::class);
            $autowiredAttr = $param->getAttributes(Autowired::class);
            $inject = !empty($injectAttr)
                ? $injectAttr[0]->newInstance()
                : (!empty($autowiredAttr) ? new Inject() : null);

            $type     = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType && !$type->isBuiltin())
                ? $type->getName()
                : null;

            $result[] = [
                'name'       => $param->getName(),
                'type'       => $typeName,
                'inject'     => $inject,
                'lazy'       => !empty($param->getAttributes(Lazy::class)),
                'optional'   => $param->isOptional(),
                'hasDefault' => $param->isDefaultValueAvailable(),
                'default'    => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }
        return $result;
    }

    private function buildArgs(array $params, Container $container, array $overrides, ?string $consumer = null): array
    {
        $args = [];
        foreach ($params as $p) {
            // Manual override by parameter name
            if (array_key_exists($p['name'], $overrides)) {
                $args[] = $overrides[$p['name']];
                continue;
            }

            // #[Inject] attribute — explicit id or fallback to type
            if ($p['inject'] !== null) {
                $id = $p['inject']->id ?? $p['type'];
                if ($id !== null) {
                    $args[] = $p['lazy']
                        ? $this->lazyProxy($id, $container, $consumer)
                        : $container->makeContextual($id, $consumer);
                    continue;
                }
            }

            // Autowire by type
            if ($p['type'] !== null) {
                if ($p['lazy']) {
                    $args[] = $this->lazyProxy($p['type'], $container, $consumer);
                    continue;
                }
                try {
                    $args[] = $container->makeContextual($p['type'], $consumer);
                    continue;
                } catch (NotFoundException $e) {
                    if ($p['hasDefault']) {
                        $args[] = $p['default'];
                        continue;
                    }
                    throw $e;
                }
            }

            // Default value
            if ($p['hasDefault']) {
                $args[] = $p['default'];
                continue;
            }

            if ($p['optional']) {
                continue;
            }

            throw new ContainerException(
                "Cannot resolve parameter [{$p['name']}] — no type hint, no default, no override."
            );
        }
        return $args;
    }

    /**
     * Build a native lazy proxy (PHP 8.4) for $type — resolution is deferred to
     * first access, which is how a #[Lazy] injection breaks a circular dependency.
     * The proxy must stand in for a concrete class; interfaces / abstracts cannot
     * be proxied (pair #[Lazy] with #[Inject(Concrete::class)] for those).
     */
    private function lazyProxy(string $type, Container $container, ?string $consumer): object
    {
        if (!class_exists($type)) {
            throw new ContainerException(
                "#[Lazy] requires a concrete class to proxy, got [{$type}]. "
                . 'Pair it with #[Inject(Concrete::class)] for interface-typed dependencies.'
            );
        }

        $ref = ReflectionCache::classOf($type);
        if ($ref->isAbstract()) {
            throw new ContainerException("#[Lazy] cannot proxy an abstract class [{$type}].");
        }

        return $ref->newLazyProxy(
            static fn(object $proxy): object => $container->makeContextual($type, $consumer)
        );
    }
}
