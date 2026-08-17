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
 *   Container::init()->register(AppServiceProvider::class);
 *
 *   Scanner::run($rootDir)               // attribute discovery is the Scanner's job
 *       ->collect(new DICollector(Container::getInstance()))
 *       ->execute();
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
 *
 * @link https://winterframe.net/packages/di/api-reference#container Registration, resolution and scope boundaries
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

    /**
     * @var array<string, true>
     * Resolution stack for the circular dependency guard, off the coroutine path only —
     * under Swoole it lives in the coroutine's context. See {@see buildStack()}.
     */
    private array $building = [];

    /**
     * @var array<string, \Swoole\Coroutine\Channel>
     * Singletons currently being built, one channel per class. A coroutine that wants a
     * singleton somebody else has already started waits on its channel instead of
     * building a second copy; the builder closes it, which wakes every waiter at once.
     */
    private array $singletonBuilds = [];

    /** Key the per-coroutine resolution stack is stored under. */
    private const string BUILD_KEY = '__di_building';

    /**
     * How long a coroutine waits for a singleton another one is building.
     *
     * A bound rather than an endless wait: if the builder dies in a way that skips its
     * `finally` — a killed coroutine, a fatal — the waiters must not hang with it. Past
     * the bound they build the instance themselves, which is what would have happened
     * without any waiting at all, so the worst case degrades to the old behaviour instead
     * of a deadlock.
     */
    private const float SINGLETON_WAIT_SECONDS = 5.0;

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
        if ($this->inCoroutine()) {
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
        $this->forget($abstract);
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
        $this->forget($abstract);
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
        $this->forget($abstract);
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
        $this->forget($abstract);
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

        // Circular dependency guard — the stack of this unit of work, nobody else's
        $stack = $this->buildStack();
        if (isset($stack[$abstract])) {
            throw new ContainerException(
                'Circular dependency detected while resolving ['
                . implode('] → [', [...array_keys($stack), $abstract])
                . '].'
            );
        }

        // Somebody else is already building this singleton — wait for theirs
        if ($scope === 'singleton' && empty($overrides) && $this->awaitSingleton($abstract)) {
            return $this->resolved[$abstract];
        }

        $channel = $this->beginBuild($abstract, $scope, empty($overrides));

        try {
            $instance = $this->doResolve($abstract, $overrides);
            $this->resolver->injectProperties($instance, $this);

            if (empty($overrides)) {
                $this->cache($abstract, $scope, $instance);
            }

            return $instance;
        } finally {
            $this->endBuild($abstract, $channel);
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

    /**
     * Drops everything a previous scope left behind for $abstract, so re-registering it
     * actually takes effect.
     *
     * Two things are left behind, and both have to go. The cached instance, because
     * {@see make()} answers from the cache before it ever looks at the scope — a singleton
     * built earlier would keep being handed out, and the new registration would silently
     * do nothing. And the request-scope marker, because {@see flushRequestScope()} walks
     * that list: a class that was request-scoped, got built, and was then re-registered as
     * a singleton would have its new instance dropped at the end of the next request —
     * a singleton that quietly stops being one.
     */
    private function forget(string $abstract): void
    {
        unset($this->resolved[$abstract], $this->requestScoped[$abstract]);
    }

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
        if ($this->inCoroutine()) {
            return (array) (\Swoole\Coroutine::getContext()['__di'] ?? []);
        }
        return null;
    }

    private function inCoroutine(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    // ── Resolution stack ──────────────────────────────────────────────────────

    /**
     * The resolution stack of the current unit of work.
     *
     * Under Swoole one worker process serves many requests at once as coroutines, so a
     * stack kept in a property would belong to all of them together. A request that pauses
     * on I/O halfway through a resolution — a bean factory opening a Redis connection, say
     * — would then make every other request resolving the same class see it as "already
     * being built", and they would fail with a circular dependency that does not exist.
     * The coroutine is the unit of work, so the stack lives in its context; off that path
     * (FPM, CLI) a process handles one unit at a time and the property means the same.
     *
     * @return array<string, true> Insertion-ordered, so it doubles as the cycle's path.
     */
    private function buildStack(): array
    {
        if ($this->inCoroutine()) {
            return (array) (\Swoole\Coroutine::getContext()[self::BUILD_KEY] ?? []);
        }

        return $this->building;
    }

    /**
     * @param bool $shareable Whether the result will be cached, i.e. worth waiting for.
     * @return \Swoole\Coroutine\Channel|null The channel other coroutines wait on, if any.
     */
    private function beginBuild(string $abstract, string $scope, bool $shareable): ?object
    {
        if (!$this->inCoroutine()) {
            $this->building[$abstract] = true;
            return null;
        }

        \Swoole\Coroutine::getContext()[self::BUILD_KEY][$abstract] = true;

        // Only a build whose result gets cached is worth waiting for. A transient is a new
        // object by contract and a request-scoped one belongs to a single coroutine, so
        // neither can be shared — and a build with overrides is deliberately never cached
        // (see make()), so a waiter would sleep through it only to find nothing and build
        // its own anyway.
        if ($scope !== 'singleton' || !$shareable) {
            return null;
        }

        return $this->singletonBuilds[$abstract] = new \Swoole\Coroutine\Channel(1);
    }

    private function endBuild(string $abstract, ?object $channel): void
    {
        if (!$this->inCoroutine()) {
            unset($this->building[$abstract]);
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        unset($ctx[self::BUILD_KEY][$abstract]);

        if ($channel === null) {
            return;
        }

        // Drop the map entry only while it is still ours. A concurrent build may have
        // replaced it, and removing somebody else's would leave their waiters asleep until
        // the bound expires — while our own waiters are woken by closing our own channel.
        if (($this->singletonBuilds[$abstract] ?? null) === $channel) {
            unset($this->singletonBuilds[$abstract]);
        }

        $channel->close();   // wakes every waiter of this build, success or failure alike
    }

    /**
     * Waits out a singleton another coroutine has already started building.
     *
     * Without this, moving the guard into the coroutine would trade a loud error for a
     * quiet defect: two coroutines racing on the first resolution would each build their
     * own instance, one overwriting the other in the cache while the loser keeps using an
     * orphan — a singleton that is not one, with the factory's side effects run twice.
     * Waiting costs nothing, since the waiter wakes no later than it would have finished
     * building that second copy itself.
     *
     * @return bool Whether the instance is in the cache now, so the caller can return it.
     */
    private function awaitSingleton(string $abstract): bool
    {
        while (isset($this->singletonBuilds[$abstract])) {
            // Returns as soon as the builder closes the channel; false on the bound, and
            // then the loop re-checks rather than trusting the wake-up reason.
            $this->singletonBuilds[$abstract]->pop(self::SINGLETON_WAIT_SECONDS);

            if (array_key_exists($abstract, $this->resolved)) {
                return true;
            }

            // Builder failed, or the wait ran out — build it here instead of hanging on.
            break;
        }

        return array_key_exists($abstract, $this->resolved);
    }
}
