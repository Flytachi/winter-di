<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Resolver;

use Flytachi\Winter\DI\Attribute\Inject;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Exception\ContainerException;
use Flytachi\Winter\DI\Exception\NotFoundException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class ReflectionResolver
{
    /** @var array<string, list<array>> Cached parameter metadata keyed by class/method */
    private static array $cache = [];

    public function resolve(string $class, Container $container, array $overrides = []): object
    {
        if (!class_exists($class)) {
            throw new NotFoundException("Class [{$class}] does not exist.");
        }

        $params = $this->constructorParams($class);

        if (empty($params)) {
            return new $class();
        }

        $args = $this->buildArgs($params, $container, $overrides);
        return new $class(...$args);
    }

    public function call(callable|array $callable, Container $container, array $overrides = []): mixed
    {
        if (is_array($callable)) {
            [$target, $method] = $callable;
            $instance = is_string($target) ? $container->make($target) : $target;
            $ref    = new ReflectionMethod($instance, $method);
            $params = $this->methodParams($ref);
            $args   = $this->buildArgs($params, $container, $overrides);
            return $ref->invoke($instance, ...$args);
        }

        $ref    = new ReflectionFunction(\Closure::fromCallable($callable));
        $params = $this->extractParams($ref->getParameters());
        $args   = $this->buildArgs($params, $container, $overrides);
        return $ref->invoke(...$args);
    }

    public function injectProperties(object $instance, Container $container): void
    {
        $ref = new ReflectionClass($instance);
        foreach ($ref->getProperties() as $property) {
            // Skip constructor-promoted properties — already set via constructor injection
            if ($property->isPromoted()) {
                continue;
            }

            $attrs = $property->getAttributes(Inject::class);
            if (empty($attrs)) {
                continue;
            }

            /** @var Inject $inject */
            $inject = $attrs[0]->newInstance();
            $type   = $inject->id ?? $property->getType()?->getName();

            if ($type === null) {
                throw new ContainerException(
                    "Cannot inject property [{$property->getName()}] — no type and no #[Inject] id."
                );
            }

            $property->setValue($instance, $container->make($type));
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function constructorParams(string $class): array
    {
        $key = 'ctor:' . $class;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $ref         = new ReflectionClass($class);
        $constructor = $ref->getConstructor();

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
            $injectAttr = $param->getAttributes(Inject::class);
            $inject     = !empty($injectAttr) ? $injectAttr[0]->newInstance() : null;

            $type     = $param->getType();
            $typeName = ($type instanceof ReflectionNamedType && !$type->isBuiltin())
                ? $type->getName()
                : null;

            $result[] = [
                'name'       => $param->getName(),
                'type'       => $typeName,
                'inject'     => $inject,
                'optional'   => $param->isOptional(),
                'hasDefault' => $param->isDefaultValueAvailable(),
                'default'    => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }
        return $result;
    }

    private function buildArgs(array $params, Container $container, array $overrides): array
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
                    $args[] = $container->make($id);
                    continue;
                }
            }

            // Autowire by type
            if ($p['type'] !== null) {
                try {
                    $args[] = $container->make($p['type']);
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
}
