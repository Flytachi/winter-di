# Winter DI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-di.svg)](https://packagist.org/packages/flytachi/winter-di)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-di.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-di)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

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
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\DI\Collector\DICollector;

// bootstrap.php — once at application start
$container = Container::init();

Scanner::run(__DIR__ . '/src', cache: __DIR__ . '/var/cache/di.php')
    ->collect(new DICollector($container))  // auto-register #[Singleton], #[Request], #[Transient]
    ->execute();

$container->register(AppServiceProvider::class); // bind interfaces and factories

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
use Flytachi\Winter\DI\Attribute\Autowired;
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
        private UserRepository $repo,               // autowired by type (no attribute needed)

        #[Inject(FileCache::class)]
        private CacheInterface $fallback,           // specific implementation

        #[Inject('config.timeout')]
        private int $timeout,                       // named value
    ) {}
}

// Property injection (when constructor is unavailable)
class SomeCommand
{
    #[Autowired]                                    // by declared type — idiomatic choice
    private UserService $service;

    #[Inject(FileCache::class)]                     // specific implementation override
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

## Scanner

`Scanner` walks the project tree once and dispatches every discovered class to all
registered `CollectorInterface` implementations — a single filesystem pass, multiple consumers.

```php
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\DI\Collector\DICollector;

// Without cache — always scans (dev mode, PPA, Cmd, Db collectors)
Scanner::run($rootDir)
    ->collect(new PpaCollector())
    ->collect(new CmdCollector())
    ->execute();

// With cache — skips FS walk on cache hit (production)
Scanner::run($rootDir, cache: '/var/cache/scanner.php')
    ->collect(new DICollector($container))
    ->collect(new MappingCollector($router))
    ->execute();

// Exclude additional directories (vendor/ is always excluded)
Scanner::run($rootDir)
    ->exclude(['/path/to/legacy', '/path/to/generated'])
    ->collect(new DICollector($container))
    ->execute();
```

The cache stores only the list of discovered FQCNs as a plain PHP file — fast `require`,
no serialization overhead. Delete the file to force a rescan.

---

## ReflectionCache

Per-process cache for reflection objects. Creates each `ReflectionClass`,
`ReflectionMethod`, and `ReflectionParameter[]` once and reuses it for the
process lifetime — critical in Swoole where workers handle many requests.

```php
use Flytachi\Winter\DI\ReflectionCache;

$ref    = ReflectionCache::classOf(UserService::class);   // ReflectionClass
$method = ReflectionCache::method(UserService::class, 'handle'); // ReflectionMethod
$params = ReflectionCache::parameters(UserService::class, 'handle'); // ReflectionParameter[]
```

Used internally by `ReflectionResolver` — available as a public utility for
frameworks and libraries that perform their own reflection-based parameter resolution.

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
| [04-attributes.md](docs/04-attributes.md) | `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Autowired]`, `#[Inject]` |
| [05-providers.md](docs/05-providers.md) | ServiceProvider — grouping bindings |
| [06-scan.md](docs/06-scan.md) | Directory scan — auto-discovery |
| [07-reflection-cache.md](docs/07-reflection-cache.md) | ReflectionCache — per-process reflection object cache |

---

## License

MIT License. See [LICENSE](LICENSE).
