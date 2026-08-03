<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Contract\ServiceProvider;
use Flytachi\Winter\DI\Exception\ContainerException;
use Flytachi\Winter\DI\Exception\NotFoundException;
use Flytachi\Winter\DI\Resolver\ReflectionResolver;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * PSR-11 DI container with autowiring, scopes and attribute-based configuration.
 *
 * Bootstrap:
 * ```
 *   Container::init()
 *       ->scan(Kernel::$pathRoot)
 *       ->register(AppServiceProvider::class);
 * ```
 *
 * Resolution:
 * ```
 *   $service = Container::getInstance()->make(UserService::class);
 *   $result  = Container::getInstance()->call([UserController::class, 'index']);
 * ```
 *
 * Scopes:
 *   * singleton — one instance per container (process)
 *   * request   — one instance per HTTP request / coroutine
 *   * transient — new instance on every make()
 *
 * Default scope when no attribute and no manual registration: transient.
 */
final class Container implements ContainerInterface
{
    private static ?self $instance = null;

    /**
     * @var array<string, array{concrete: string|callable, scope: string}>
     * Manual bindings registered via bind() / singleton() / transient() / request().
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed>
     * Singleton and request-scope cache for FPM/CLI (process-level).
     * Named scalar values registered via set() also live here.
     */
    private array $resolved = [];

    /**
     * @var array<string, true>
     * Which keys in $resolved hold request-scoped instances, so
     * {@see flushRequestScope()} can drop those without walking every entry or
     * re-deriving scopes. Only used off the coroutine path — under Swoole the
     * instances live in the coroutine context instead.
     */
    private array $requestScoped = [];

    /**
     * @var array<string, callable>
     * Consumer-aware factories registered via contextual(). Each factory receives
     * (Container $c, ?string $consumer) and is invoked only during dependency
     * injection — its result is never cached and a direct make()/get() ignores it.
     */
    private array $contextual = [];

    /** @var array<string, true> Circular dependency guard */
    private array $building = [];

    private ReflectionResolver $resolver;

    private function __construct()
    {
        $this->resolver = new ReflectionResolver();

        // Self-registration — container can be injected as a dependency
        $this->resolved[self::class]           = $this;
        $this->resolved[ContainerInterface::class] = $this;
    }

    // ── Initialisation ────────────────────────────────────────────────────────

    public static function init(): static
    {
        self::$instance = new static();
        return self::$instance;
    }

    public static function getInstance(): static
    {
        return self::$instance
            ?? throw new ContainerException(
                'Container is not initialized. Call Container::init() at bootstrap.'
            );
    }

    /**
     * Ends the current request scope: the next resolution of a `#[Request]` binding
     * builds a fresh instance.
     *
     * Under HTTP the scope ends by itself — a request is a coroutine, and its context
     * dies with it. Nothing else has that boundary. A long-lived worker looping over
     * jobs runs its whole body in **one** coroutine, so a request-scoped bean resolved
     * there survives every iteration and silently carries the previous job's state; off
     * the coroutine path (FPM helpers, plain CLI) it lands in the process cache and does
     * the same. Only the caller knows where one unit of work ends and the next begins,
     * which is why this is explicit.
     *
     * Singletons are untouched — the point is to end a scope, not to reset the container.
     */
    public function flushRequestScope(): void
    {
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            unset($ctx['__di']);
        }

        foreach (array_keys($this->requestScoped) as $abstract) {
            unset($this->resolved[$abstract]);
        }

        $this->requestScoped = [];
    }

    /**
     * Whether a container exists yet.
     *
     * For code that can work with or without one — a library reachable both from a booted
     * application and from a bare script. Such code asks first instead of catching the
     * exception {@see getInstance()} throws, since a missing container is a legitimate
     * state there, not an error.
     */
    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    // ── PSR-11 ────────────────────────────────────────────────────────────────

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id])
            || isset($this->resolved[$id])
            || class_exists($id);
    }

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Bind an abstract to a concrete class or factory closure (transient scope).
     *
     *   $c->bind(CacheInterface::class, RedisCache::class);
     *   $c->bind(MailerInterface::class, fn($c) => new SmtpMailer(env('MAIL_HOST')));
     */
    public function bind(string $abstract, string|callable $concrete): static
    {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'scope' => 'transient'];
        unset($this->resolved[$abstract]);
        return $this;
    }

    /**
     * Register a singleton binding (one instance per process).
     *
     *   $c->singleton(DatabaseConnection::class);
     *   $c->singleton(CacheInterface::class, RedisCache::class);
     */
    public function singleton(string $abstract, string|callable|null $concrete = null): static
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'scope' => 'singleton'];
        unset($this->resolved[$abstract]);
        return $this;
    }

    /**
     * Register a transient binding (new instance on every make()).
     *
     *   $c->transient(QueryBuilder::class);
     */
    public function transient(string $abstract, string|callable|null $concrete = null): static
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'scope' => 'transient'];
        return $this;
    }

    /**
     * Register a request-scoped binding.
     * One instance per HTTP request / coroutine. In FPM/CLI equals singleton.
     *
     *   $c->request(AuthContext::class);
     */
    public function request(string $abstract, string|callable|null $concrete = null): static
    {
        $this->bindings[$abstract] = ['concrete' => $concrete ?? $abstract, 'scope' => 'request'];
        unset($this->resolved[$abstract]);
        return $this;
    }

    /**
     * Register a consumer-aware factory for an abstract.
     *
     * Unlike bind(), the factory also receives the CONSUMER class — the class the
     * dependency is being injected into — so it can tailor the instance per consumer
     * (e.g. a logger named after the class that uses it):
     *
     *   $c->contextual(LoggerInterface::class,
     *       fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'));
     *
     * Applies during constructor / method / property injection only and acts as an
     * overlay: at injection time it takes precedence over a regular bind()/singleton()/…
     * of $abstract, while a direct make()/get() still uses the regular binding. The
     * result is never cached (the factory owns identity). Re-register to override.
     */
    public function contextual(string $abstract, callable $factory): static
    {
        $this->contextual[$abstract] = $factory;
        return $this;
    }

    /**
     * Register a named scalar value or pre-built instance.
     *
     *   $c->set('config.timeout', 30);
     *   $c->set('app.name', env('APP_NAME'));
     */
    public function set(string $id, mixed $value): static
    {
        $this->resolved[$id] = $value;
        return $this;
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    /**
     * Resolve an abstract — class, interface, or named value.
     *
     * @param array<string, mixed> $overrides  Named parameter overrides (bypass autowiring)
     */
    public function make(string $abstract, array $overrides = []): mixed
    {
        // Named scalar / pre-built instance (set() or already resolved singleton)
        if (empty($overrides) && array_key_exists($abstract, $this->resolved)) {
            return $this->resolved[$abstract];
        }

        $scope = $this->scopeOf($abstract);

        // Request scope — check coroutine context first (Swoole)
        if ($scope === 'request' && empty($overrides)) {
            $ctx = $this->coroutineContext();
            if ($ctx !== null && array_key_exists($abstract, $ctx)) {
                return $ctx[$abstract];
            }
        }

        // Circular dependency guard
        if (isset($this->building[$abstract])) {
            throw new ContainerException(
                "Circular dependency detected while resolving [{$abstract}]."
            );
        }
        $this->building[$abstract] = true;

        try {
            $instance = $this->doResolve($abstract, $overrides);
            $this->resolver->injectProperties($instance, $this);

            if (empty($overrides)) {
                $this->cache($abstract, $scope, $instance);
            }

            return $instance;
        } finally {
            unset($this->building[$abstract]);
        }
    }

    /**
     * Fill the `#[Autowired]` / `#[Inject]` properties of an object the caller built.
     *
     * The second half of {@see make()}, exposed on its own. `make()` builds *and* injects,
     * which is right whenever the container owns construction; it is wrong when the caller
     * must control the object's identity. A repository handle carrying a query alias is
     * the case this was added for: the alias lives in per-object state, so two aliases of
     * one table need two distinct objects, and resolving them through `make()` on a shared
     * binding would collapse both into one and silently lose an alias.
     *
     * The instance is returned as passed — never swapped — and its constructor state is
     * left alone. Calling this twice on the same object is harmless.
     *
     * ```
     * $repository = new static();
     * Container::getInstance()->inject($repository);
     * ```
     *
     * @template T of object
     * @param T $instance
     * @return T The same instance, with its injectable properties filled.
     */
    public function inject(object $instance): object
    {
        $this->resolver->injectProperties($instance, $this);

        return $instance;
    }

    /**
     * Resolve an abstract for a specific consumer class (the class it is injected into).
     *
     * If a contextual() factory is registered for $abstract, it is invoked with the
     * consumer (result not cached); otherwise this is a plain make($abstract). Used by
     * the resolver for dependency injection — application code calls make().
     */
    public function makeContextual(string $abstract, ?string $consumer): mixed
    {
        if (isset($this->contextual[$abstract])) {
            return ($this->contextual[$abstract])($this, $consumer);
        }
        return $this->make($abstract);
    }

    /**
     * Call a method or closure, resolving its parameters from the container.
     *
     *   $container->call([UserController::class, 'index']);
     *   $container->call([new UserController(), 'index']);
     *   $container->call(fn(UserService $s) => $s->all());
     *
     * @param array<string, mixed> $overrides  Named parameter overrides
     */
    public function call(callable|array $callable, array $overrides = []): mixed
    {
        return $this->resolver->call($callable, $this, $overrides);
    }

    // ── Providers ─────────────────────────────────────────────────────────────

    /**
     * Register a ServiceProvider — groups related bindings together.
     *
     *   $container->register(AppServiceProvider::class);
     */
    public function register(string $providerClass): static
    {
        $provider = new $providerClass();
        if (!$provider instanceof ServiceProvider) {
            throw new ContainerException(
                "[{$providerClass}] must extend " . ServiceProvider::class . '.'
            );
        }
        $provider->register($this);
        return $this;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function doResolve(string $abstract, array $overrides): mixed
    {
        $binding = $this->bindings[$abstract] ?? null;

        if ($binding !== null) {
            $concrete = $binding['concrete'];
            if (is_callable($concrete)) {
                return $concrete($this);
            }
            return $this->resolver->resolve($concrete, $this, $overrides);
        }

        if (!class_exists($abstract)) {
            throw new NotFoundException(
                "No binding found for [{$abstract}] and it is not an instantiable class."
            );
        }

        return $this->resolver->resolve($abstract, $this, $overrides);
    }

    private function scopeOf(string $abstract): string
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['scope'];
        }

        if (class_exists($abstract)) {
            $ref = new ReflectionClass($abstract);
            if (!empty($ref->getAttributes(Singleton::class))) {
                return 'singleton';
            }
            if (!empty($ref->getAttributes(Request::class))) {
                return 'request';
            }
            if (!empty($ref->getAttributes(Transient::class))) {
                return 'transient';
            }
        }

        return 'transient';
    }

    private function cache(string $abstract, string $scope, mixed $instance): void
    {
        match ($scope) {
            'singleton' => $this->resolved[$abstract] = $instance,
            'request'   => $this->cacheRequest($abstract, $instance),
            default     => null,
        };
    }

    private function cacheRequest(string $abstract, mixed $instance): void
    {
        $ctx = $this->coroutineContext();
        if ($ctx !== null) {
            \Swoole\Coroutine::getContext()['__di'][$abstract] = $instance;
        } else {
            // FPM / CLI — process = request, use process-level cache
            $this->resolved[$abstract] = $instance;
            $this->requestScoped[$abstract] = true;
        }
    }

    /** Returns Swoole coroutine context array or null if not in a coroutine. */
    private function coroutineContext(): ?array
    {
        if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
            return (array) (\Swoole\Coroutine::getContext()['__di'] ?? []);
        }
        return null;
    }
}
