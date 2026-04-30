# Attributes

Attributes let classes declare their own scope and injection requirements without
registering them manually in bootstrap. The container reads them during `scan()` or
on first `make()`.

Manual registration always takes priority over attributes.

---

## `#[Singleton]`

```php
use Flytachi\Winter\DI\Attribute\Singleton;
```

Marks a class as singleton-scoped. One instance per container lifetime.

```php
#[Singleton]
class UserRepository
{
    public function __construct(private DatabaseConnection $db) {}
}
```

Equivalent to:
```php
$container->singleton(UserRepository::class);
```

---

## `#[Transient]`

```php
use Flytachi\Winter\DI\Attribute\Transient;
```

Marks a class as transient-scoped. New instance on every `make()`.

```php
#[Transient]
class QueryBuilder
{
    private array $clauses = [];
}
```

Equivalent to:
```php
$container->transient(QueryBuilder::class);
```

> **Note:** transient is the default scope even without this attribute.
> Use `#[Transient]` to make the intent explicit and visible in code review.

---

## `#[Request]`

```php
use Flytachi\Winter\DI\Attribute\Request;
```

Marks a class as request-scoped. One instance per HTTP request / coroutine.

```php
#[Request]
class AuthContext
{
    private ?User $currentUser = null;

    public function setUser(User $u): void { $this->currentUser = $u; }
    public function user(): ?User { return $this->currentUser; }
}
```

Equivalent to:
```php
$container->request(AuthContext::class);
```

---

## `#[Inject]`

```php
use Flytachi\Winter\DI\Attribute\Inject;
```

Overrides autowiring for a specific **constructor parameter** or **class property**.

### On a constructor parameter — resolve by type (explicit marker)

Without an argument `#[Inject]` behaves identically to plain autowiring.
Use it as an explicit signal that this parameter is injected:

```php
public function __construct(
    #[Inject] private UserRepository $repo,
) {}
```

### On a constructor parameter — resolve a specific implementation

Overrides the global binding for this one parameter:

```php
// Global binding: CacheInterface → RedisCache
$container->bind(CacheInterface::class, RedisCache::class);

class UserService
{
    public function __construct(
        private CacheInterface $primary,               // → RedisCache (global binding)

        #[Inject(FileCache::class)]
        private CacheInterface $fallback,              // → FileCache (local override)
    ) {}
}
```

### On a constructor parameter — resolve a named value

```php
$container->set('config.timeout', 30);
$container->set('app.name', 'Winter');

class ApiClient
{
    public function __construct(
        #[Inject('config.timeout')] private int $timeout,
        #[Inject('app.name')]       private string $appName,
    ) {}
}
```

### On a property — property injection

When constructor injection is not possible (parent class, legacy code):

```php
class SomeCommand extends Cmd
{
    #[Inject]
    private UserService $userService;

    #[Inject(FileCache::class)]
    private CacheInterface $cache;

    public function handle(): void
    {
        $this->userService->sync();
    }
}
```

Property injection runs automatically after the constructor during `make()`.
The property does not need to be public — `setAccessible(true)` is used internally.
