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
    ->scan(Kernel::$pathRoot)
    ->register(AppServiceProvider::class);
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

Explicitly registers a **transient** binding. Equivalent to `bind()` when `$concrete` is a class string,
but allows self-binding without specifying the concrete.

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
3. Manual binding (`bind()` / `singleton()` / `request()`)
4. Autowiring by class name (reflection + recursive resolution)

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
