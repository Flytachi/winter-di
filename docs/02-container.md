# Container API

---

## Initialisation

### `Container::init(): static`

Creates a new container instance and stores it as the static singleton.
Must be called once at application bootstrap before any `make()` call.

```php
$container = Container::init();
```

Returns the container itself for fluent chaining:

```php
Container::init()
    ->register(AppServiceProvider::class)
    ->register(DatabaseServiceProvider::class);
```

The container itself does not scan anything — discovery of `#[Singleton]` / `#[Request]` /
`#[Transient]` classes belongs to [`Scanner`](06-scan.md) plus `DICollector`:

```php
Scanner::run($rootDir)
    ->collect(new DICollector(Container::getInstance()))
    ->execute();
```

---

### `Container::getInstance(): static`

Returns the already-initialised container. Throws `ContainerException` if `init()` was not called.

```php
$container = Container::getInstance();
```

---

## Binding

### `bind(string $abstract, string|callable $concrete): static`

Registers a **transient** binding — a new instance is created on every `make()`.
Use for interface → implementation mapping and factory closures.

```php
// Class string
$c->bind(CacheInterface::class, RedisCache::class);

// Factory closure — receives the container as argument
$c->bind(MailerInterface::class, fn(Container $c) =>
    new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
);
```

---

### `singleton(string $abstract, string|callable|null $concrete = null): static`

Registers a **singleton** binding — one instance per container (process) lifetime.

```php
// Self-bind — resolves DatabaseConnection by autowiring
$c->singleton(DatabaseConnection::class);

// With explicit concrete
$c->singleton(CacheInterface::class, RedisCache::class);

// With factory
$c->singleton(CacheInterface::class, fn($c) => new RedisCache(env('REDIS_HOST')));
```

---

### `transient(string $abstract, string|callable|null $concrete = null): static`

Explicitly registers a **transient** binding. Same scope as `bind()`, but the concrete may
be omitted for self-binding — `bind()` requires it.

Like every other registration method, this drops whatever the previous scope left for
`$abstract` — the cached instance **and** its place on the request-scope flush list — so an
override applies even to a class that was already resolved.

```php
$c->transient(QueryBuilder::class);
$c->transient(CacheInterface::class, FileCache::class);
```

---

### `request(string $abstract, string|callable|null $concrete = null): static`

Registers a **request-scoped** binding.
One instance per HTTP request / coroutine. In FPM/CLI behaves as singleton.

```php
$c->request(AuthContext::class);
$c->request(UnitOfWork::class, fn($c) => new UnitOfWork($c->make(Connection::class)));
```

---

### `contextual(string $abstract, callable $factory): static`

Registers a **consumer-aware** factory. Unlike `bind()`, the factory also
receives the *consumer* — the class the dependency is being injected into —
so it can tailor the instance per consumer. The classic use is a logger named
after the class that uses it:

```php
$c->contextual(
    LoggerInterface::class,
    fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'),
);
```

```php
class MainController {
    #[Autowired] private LoggerInterface $logger;   // → getLogger(MainController::class)
}
```

Semantics (an **overlay**, like contextual binding in other containers):

- Applies during **dependency injection only** — constructor parameters,
  method parameters (via `call()`), and `#[Autowired]` / `#[Inject]` properties.
- At injection time it **takes precedence** over a regular `bind()`/`singleton()`/…
  of the same `$abstract`. A direct `make()` / `get()` is unaffected — it still
  uses the regular binding (or resolves the class normally).
- **Override** by re-registering: a later `contextual()` for the same `$abstract`
  replaces the factory. (Plain bindings and the contextual overlay coexist — they
  are not "last write wins".)
- The result is **never cached** by the container — the factory runs on every
  injection. Give it its own cache if the build is expensive (e.g.
  `LoggerFactory` already caches per `channel:class`).
- `$consumer` is the consumer's FQCN, or `null` for free-closure injection
  (`call(fn(LoggerInterface $l) => …)`) where there is no owning class.
- If the consumer is a generated proxy, `$consumer` is the class it **stands
  for**, not the generated name — see [Proxies](08-proxies.md). A per-class
  logger keeps its channel when a service is proxied.

---

### `set(string $id, mixed $value): static`

Stores a pre-built value or scalar under a named key.
Useful for configuration values that need to be injectable.

```php
$c->set('config.timeout', 30);
$c->set('app.name', env('APP_NAME', 'Winter'));
$c->set('db.connection', $existingPdoInstance);

// Inject by name
class ApiClient {
    public function __construct(
        #[Inject('config.timeout')] private int $timeout,
    ) {}
}
```

---

## Resolution

### `make(string $abstract, array $overrides = []): mixed`

Resolves an abstract — a class, interface, or named value — from the container.

Resolution order:
1. Already-resolved singleton / set value (cache hit → zero overhead)
2. Request-scope cache (Swoole coroutine context)
3. Cycle check against **this** unit of work's resolution stack
4. A singleton another coroutine is already building → wait for it, do not build a second
5. Manual binding (`bind()` / `singleton()` / `request()`)
6. Autowiring by class name (reflection + recursive resolution)

Steps 3 and 4 are what make concurrent resolution safe — see
[Scopes → Concurrent resolution](03-scopes.md#concurrent-resolution).

```php
$service = $container->make(UserService::class);

// With parameter overrides (bypasses autowiring for named params)
$job = $container->make(ImportJob::class, ['chunkSize' => 500]);
```

---

### `call(callable|array $callable, array $overrides = []): mixed`

Calls a method or closure, resolving all parameters from the container.
The main integration point for controllers, commands and jobs.

```php
// [class-string, method] — resolves the class first, then calls the method
$result = $container->call([UserController::class, 'index']);

// [object, method] — uses the existing instance
$result = $container->call([$controller, 'store']);

// Closure — resolves all typed parameters
$result = $container->call(fn(UserService $s, AuthContext $a) => $s->current($a->user()));

// With overrides
$result = $container->call([ImportJob::class, 'run'], ['chunkSize' => 100]);
```

---

### `makeContextual(string $abstract, ?string $consumer): mixed`

The resolver's entry point for contextual injection: if a `contextual()`
factory is registered for `$abstract`, it is invoked with `$consumer` (result
not cached); otherwise it delegates to `make()`. Application code normally uses
`make()` — this exists for the injection machinery and is rarely called directly.

---

### `inject(object $instance): object`

Fills the `#[Autowired]` / `#[Inject]` properties of an object **the caller built**, and
returns that same instance. The second half of `make()`, exposed on its own.

`make()` builds and injects together, which is right whenever the container owns
construction. It is wrong when the caller must control the object's identity:

```php
public static function instance(?string $alias = null): static
{
    $repository = new static();                     // identity stays with the caller
    Container::getInstance()->inject($repository);  // dependencies still arrive

    if ($alias !== null) {
        $repository->as($alias);
    }
    return $repository;
}
```

A repository handle is the case this was added for. Its alias lives in per-object state,
so joining one table twice needs two distinct handles; resolving them through `make()` on
a `#[Singleton]` binding would return one shared object and the second alias would
silently overwrite the first.

The instance is never swapped and its constructor state is left alone. Injecting twice is
harmless — the second pass writes the same resolutions.

---

### `flushRequestScope(): void`

Ends the current request scope: the next resolution of a `#[Request]` binding builds a
fresh instance. Singletons and transients are untouched — this ends a scope, it does not
reset the container.

Over HTTP the scope ends by itself, because a request is a coroutine and its context dies
with it. Nothing else has that boundary:

```php
while ($this->isRunning()) {           // a worker body is ONE coroutine
    $job = $queue->pop();

    $container->flushRequestScope();    // ← declare where a unit of work begins
    $ctx = $container->make(JobContext::class);
    // ... work ...
}
```

Without the call, a request-scoped bean resolved inside the loop lives for the whole run
and hands each job the previous job's state. Only the code driving the loop knows where
one unit ends, which is why this is explicit.

---

### `Container::isInitialized(): bool`

Whether a container exists yet. For code reachable both from a booted application and
from a bare script — asking is better than catching the exception `getInstance()` throws,
because a missing container is a legitimate state there rather than an error.

```php
if (Container::isInitialized()) {
    Container::getInstance()->inject($object);
}
```

---

## PSR-11

### `get(string $id): mixed`

PSR-11 alias for `make()`. Throws `NotFoundException` if the id cannot be resolved.

### `has(string $id): bool`

Returns `true` if the id has a binding, a resolved value, or matches an existing class name.

```php
if ($container->has(CacheInterface::class)) {
    $cache = $container->get(CacheInterface::class);
}
```

## When resolution fails

Every failure arrives as a PSR-11 exception, and the message names the actual cause —
the six of them are fixed in six different places.

| What you asked for | Exception | What it tells you |
| --- | --- | --- |
| a name that exists nowhere | `NotFoundException` | `Class [X] not found. Check the autoloader and that the package providing it is installed.` |
| an interface with no binding | `NotFoundException` | `[X] is an interface and has no binding. Bind it…` |
| a trait | `NotFoundException` | `[X] is a trait and cannot be resolved.` |
| an abstract class | `ContainerException` | `[X] is abstract and cannot be instantiated. Bind it to a concrete class…` |
| an enum | `ContainerException` | `[X] is an enum and cannot be instantiated.` |
| a class with a non-public constructor | `ContainerException` | `[X] cannot be instantiated: its constructor is not public. Provide it through a factory…` |

The split follows PSR-11: `NotFoundException` means there is no entry to build,
`ContainerException` means the entry was found and could not be built.

`class_exists()` answers `true` for an abstract class and for an enum, so both used to
walk past the container's own check and die inside `new` — as a raw `\Error`, which is
not a `ContainerExceptionInterface`. Code that wrapped resolution in
`catch (ContainerExceptionInterface)` never caught them. The instantiability check runs
before construction now.

That check deliberately runs *before* the constructor rather than catching `\Error`
around it: an exception raised by the constructor's own body is the application's bug and
is left exactly as it was thrown.

```php
try {
    $service = $container->get(PaymentGateway::class);
} catch (NotFoundExceptionInterface $e) {
    // nothing to build — no binding, or the class is not installed
} catch (ContainerExceptionInterface $e) {
    // found, but not buildable — abstract, enum, private constructor, circular
}
```
