# Winter DI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-di.svg)](https://packagist.org/packages/flytachi/winter-di)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-di.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-di)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

Lightweight PSR-11 dependency injection container for the Winter framework.
Autowiring, three lifecycle scopes, attribute-based configuration, and service providers.

---

## Requirements

- PHP **8.4+**
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

One rule decides every combination:

> **A class may hold a reference to a shorter-lived object only if it does not outlive it.**

Injected properties are resolved once, when the holder is built. A `#[Singleton]` holding
a `#[Request]` bean therefore freezes the first request's instance for the worker's
lifetime — silently, and transitively through any number of intermediate classes. See
[Never hold a shorter-lived scope](docs/03-scopes.md#never-hold-a-shorter-lived-scope).

Note also that `singleton` means **one per worker process**, not one per application: with
four Swoole workers there are four instances, so a counter in a singleton field disagrees
with itself depending on which worker answered.

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

// Inherited properties are injected too, private ones included
abstract class BaseCommand
{
    #[Autowired]
    private LoggerInterface $logger;
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

// Consumer-aware factory — receives the class being injected into
$c->contextual(LoggerInterface::class, fn(Container $c, ?string $consumer) =>
    LoggerFactory::getLogger($consumer ?? 'app')                 // #[Autowired] LoggerInterface → per-class logger
);

// Resolution
$service = $c->make(UserService::class);
$service = $c->make(UserService::class, ['timeout' => 60]); // with overrides

// Method / closure injection
$result = $c->call([UserController::class, 'index']);
$result = $c->call([$controller, 'store']);
$result = $c->call(fn(UserService $s) => $s->all());
$result = $c->call([ImportJob::class, 'run'], ['chunkSize' => 500]);

// Injection into an object you built yourself
$repository = new UserRepository();
$c->inject($repository);      // fills #[Autowired] / #[Inject], returns the same object

// Ending a request scope where nothing ends it for you (worker loops)
$c->flushRequestScope();      // the next #[Request] resolution builds a fresh instance

// Is there a container yet?
Container::isInitialized();   // bool — for code that must work with or without one

// PSR-11
$c->has(UserService::class); // bool
$c->get(UserService::class);  // mixed — alias for make()
```

### `inject()` — construction and injection, separately

`make()` builds *and* injects, which is right whenever the container owns construction.
It is wrong when the caller must control the object's identity — a repository handle
carrying a query alias, for instance: the alias lives in per-object state, so joining one
table twice needs two distinct handles, and resolving them through `make()` on a shared
binding would collapse both into one and silently lose an alias.

```php
public static function instance(?string $alias = null): static
{
    $repository = new static();                     // identity stays with the caller
    Container::getInstance()->inject($repository);  // dependencies still arrive
    // ...
}
```

The instance is returned as passed — never swapped — and its constructor state is left
alone. Calling it twice is harmless.

### `flushRequestScope()` — ending a scope by hand

`#[Request]` means *one instance per unit of work*. Over HTTP the unit is obvious: a
request is a coroutine, and the scope dies with it. **A worker has no such boundary** —
its whole body runs inside one coroutine, so a request-scoped bean resolved there
survives every iteration and hands the next job the previous job's state.

```php
while ($this->isRunning()) {
    $job = $queue->pop();

    $c->flushRequestScope();               // ← this job is a new unit
    $ctx = $c->make(JobContext::class);     // fresh, every time
    // ... work ...
}
```

Singletons are untouched: ending a scope is not resetting the container.

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

### How a class is recognised

Files are **tokenised**, not pattern-matched. A missed class is registered nowhere — no
binding, no routes, nothing — and nothing reports it, so the parsing has to be exact
rather than approximately right. Tokenising costs roughly 35 µs more per file than a
regular expression, and it is paid on a cold scan only.

Recognised regardless of formatting:

```php
#[Attr] class Foo {}          // an attribute sharing the line
#[Attr] final class Foo {}
namespace App { class Foo {} } // indented inside a braced namespace
class First {} class Second {} // every class in the file, not just the first
```

Not mistaken for declarations:

```php
/* class Ghost */              // block comments
/** class Ghost */             // docblocks
$sql = 'class Ghost';          // strings and heredocs
Other::class                   // the ::class constant
new class {}                   // anonymous classes have no name
```

Interfaces, traits and enums are not collected — only classes.

### Exclusions

`vendor/` is always excluded, and `exclude()` adds more. Both sides of the comparison are
resolved with `realpath()`, so an application served through a symlink —
`current -> releases/2026…`, the usual deploy layout — is excluded correctly rather than
walked in full.

---

## ReflectionCache

Per-process cache for reflection objects. Creates each `ReflectionClass`,
`ReflectionMethod`, and `ReflectionParameter[]` once and reuses it for the
process lifetime — critical in Swoole where workers handle many requests.

```php
use Flytachi\Winter\DI\ReflectionCache;

$ref    = ReflectionCache::classOf(UserService::class);   // ReflectionClass
$enum   = ReflectionCache::enumOf(Status::class);   // ReflectionEnum
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
| [07-reflection-cache.md](docs/07-reflection-cache.md) | ReflectionCache — per-process reflection object cache, injection plan |
| [08-proxies.md](docs/08-proxies.md) | ProxyInterface — generated subclasses that stand in for a service |

---

## License

MIT License. See [LICENSE](LICENSE).
