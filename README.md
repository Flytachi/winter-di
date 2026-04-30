# winter-di

Lightweight PSR-11 dependency injection container for the Winter framework.
Autowiring, three lifecycle scopes, attribute-based configuration, and service providers.

---

## Requirements

- PHP **8.3+**
- `psr/container ^2.0`
- `ext-swoole` *(optional)* — required for `request` scope coroutine isolation

---

## Installation

```bash
composer require flytachi/winter-di
```

---

## Quick start

```php
use Flytachi\Winter\DI\Container;

// bootstrap.php — once at application start
Container::init()
    ->scan(__DIR__ . '/src')              // auto-register #[Singleton], #[Request], #[Transient]
    ->register(AppServiceProvider::class); // bind interfaces and factories

// Resolve anywhere
$service = Container::getInstance()->make(UserService::class);

// Call a method with full injection
$result = Container::getInstance()->call([UserController::class, 'index']);
```

---

## Scopes

| Scope | Lifetime | Safe in Swoole |
|-------|----------|----------------|
| `singleton` | One instance per process | ✓ if stateless |
| `transient` | New instance on every `make()` | ✓ always |
| `request` | One instance per request / coroutine | ✓ isolated via `Coroutine::getContext()` |

Default scope when no attribute and no manual registration: **transient**.

---

## Attributes

```php
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Inject;

// Scope on class
#[Singleton]
class UserRepository { }

#[Request]
class AuthContext { }

#[Transient]
class QueryBuilder { }

// Injection overrides on constructor parameters
class UserService
{
    public function __construct(
        private UserRepository $repo,               // autowired by type

        #[Inject(FileCache::class)]
        private CacheInterface $fallback,           // specific implementation

        #[Inject('config.timeout')]
        private int $timeout,                       // named value
    ) {}
}

// Property injection (when constructor is unavailable)
class SomeCommand
{
    #[Inject]
    private UserService $service;

    #[Inject(FileCache::class)]
    private CacheInterface $cache;
}
```

---

## Container API

```php
$c = Container::init();       // initialise (bootstrap)
$c = Container::getInstance();// get anywhere

// Binding
$c->bind(CacheInterface::class, RedisCache::class);              // transient
$c->singleton(CacheInterface::class, RedisCache::class);         // singleton
$c->transient(QueryBuilder::class);                              // transient (explicit)
$c->request(AuthContext::class);                                 // request-scoped
$c->set('config.timeout', 30);                                   // named scalar / instance

// Factory closure — receives the container
$c->bind(MailerInterface::class, fn(Container $c) =>
    new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
);

// Resolution
$service = $c->make(UserService::class);
$service = $c->make(UserService::class, ['timeout' => 60]); // with overrides

// Method / closure injection
$result = $c->call([UserController::class, 'index']);
$result = $c->call([$controller, 'store']);
$result = $c->call(fn(UserService $s) => $s->all());
$result = $c->call([ImportJob::class, 'run'], ['chunkSize' => 500]);

// PSR-11
$c->has(UserService::class); // bool
$c->get(UserService::class);  // mixed — alias for make()
```

---

## Service providers

```php
use Flytachi\Winter\DI\Contract\ServiceProvider;
use Flytachi\Winter\DI\Container;

class AppServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->singleton(CacheInterface::class, RedisCache::class);
        $c->request(AuthContext::class);
        $c->set('config.timeout', (int) env('APP_TIMEOUT', 30));
        $c->bind(MailerInterface::class, fn($c) =>
            new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
        );
    }
}

// bootstrap.php
Container::init()
    ->register(AppServiceProvider::class)
    ->register(DatabaseServiceProvider::class);
```

---

## Directory scan

```php
Container::init()
    ->scan(Kernel::$pathRoot)                        // auto-discover annotated classes
    ->scan(Kernel::$pathRoot, ['/path/to/exclude']); // with exclusions
```

Finds all classes with `#[Singleton]`, `#[Request]`, or `#[Transient]` and registers them.
The `vendor/` directory is always excluded. Manual registration always overrides scan.

---

## Exceptions

| Exception | When |
|-----------|------|
| `ContainerException` | Circular dependency, unresolvable parameter, provider error |
| `NotFoundException` | No binding and class does not exist |

Both implement the PSR-11 interfaces (`ContainerExceptionInterface`, `NotFoundExceptionInterface`).

---

## Documentation

Full documentation is available in [`docs/`](docs/):

| File | Contents |
|------|----------|
| [01-overview.md](docs/01-overview.md) | Features, installation, quick start |
| [02-container.md](docs/02-container.md) | Complete Container API reference |
| [03-scopes.md](docs/03-scopes.md) | Scopes — singleton, transient, request; Swoole behaviour |
| [04-attributes.md](docs/04-attributes.md) | `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Inject]` |
| [05-providers.md](docs/05-providers.md) | ServiceProvider — grouping bindings |
| [06-scan.md](docs/06-scan.md) | Directory scan — auto-discovery |

---

## License

MIT © [Flytachi](https://github.com/flytachi)
