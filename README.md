# Winter DI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-di.svg)](https://packagist.org/packages/flytachi/winter-di)
[![PHP Version Require](https://img.shields.io/packagist/php-v/flytachi/winter-di.svg?style=flat-square)](https://packagist.org/packages/flytachi/winter-di)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

A PSR-11 dependency injection container for PHP 8.4+: you declare what a class needs as typed
parameters, and the container builds the whole object graph — resolving every dependency by
type, recursively, with the lifetime you asked for.

One runtime dependency (`psr/container`), no configuration files. Built for the Winter
framework, usable in any PSR-11 consumer, and safe in a long-lived Swoole worker where one
process serves many requests at once.

📖 **[Documentation](https://winterframe.net/packages/di)** · [Quick start](https://winterframe.net/packages/di/quickstart) · [API reference](https://winterframe.net/packages/di/api-reference)

---

## Installation

```bash
composer require flytachi/winter-di
```

Requires PHP **8.4+** and `psr/container ^2.0`. `ext-swoole` is optional — it only matters for
per-coroutine isolation of the `request` scope.

---

## Quick start

```php
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\DI\Collector\DICollector;

// bootstrap.php — once at application start
$container = Container::init();

Scanner::run(__DIR__ . '/src', cache: __DIR__ . '/var/cache/di.php')
    ->collect(new DICollector($container))   // auto-register #[Singleton], #[Request], #[Transient]
    ->execute();

$container->register(AppServiceProvider::class);   // bind interfaces and factories

// Resolve anywhere
$service = Container::getInstance()->make(UserService::class);

// Call a method with everything injected
$result = Container::getInstance()->call([UserController::class, 'index']);
```

Nothing else is required: a class with typed constructor parameters resolves without being
registered at all.

---

## What you get

- **Autowiring by type** — constructor parameters resolved recursively; you stop writing `new`
  for services.
- **Three explicit lifetimes** — `singleton`, `transient`, `request`, chosen by attribute or
  binding.
- **Attributes, not config** — `#[Singleton]`, `#[Autowired]`, `#[Inject]`, `#[Lazy]` live on
  the class that they describe.
- **Property injection** — including private properties declared in a parent class.
- **Contextual factories** — `contextual()` receives the consuming class, which is how a logger
  can name its channel after whoever injected it.
- **Lazy proxies** — `#[Lazy]` injects a native PHP 8.4 proxy resolved on first use, the escape
  hatch for a circular dependency.
- **One scan for everything** — `Scanner` walks the tree once and feeds every collector;
  tokenised, so a class is found whatever its formatting, with an optional production cache.
- **Swoole-safe** — request scope and the resolution stack live in the coroutine's context;
  concurrent resolution never invents a circular dependency, and a singleton is built once even
  when a cold worker is hit by many requests at once.

---

## Scopes in one table

| Scope | Lifetime | Concurrent `make()` of the same class |
|-------|----------|---------------------------------------|
| `singleton` | one instance per **worker process** | first builds it, the rest wait and get it |
| `transient` | new instance every time *(default)* | each builds its own |
| `request` | one per request / coroutine | each coroutine has its own |

One rule decides every combination:

> **A class may hold a reference to a shorter-lived object only if it does not outlive it.**

Injected properties are resolved once, when the holder is built. A `#[Singleton]` holding a
`#[Request]` bean therefore freezes the first request's instance for the worker's lifetime —
silently, and transitively through any number of intermediate classes. With an authentication
context in that position, every user after the first is served under the first user's identity.
See [Scopes](https://winterframe.net/packages/di/scopes).

---

## A taste of the API

```php
// Registration
$c->bind(CacheInterface::class, RedisCache::class);        // transient
$c->singleton(CacheInterface::class, RedisCache::class);   // one per process
$c->request(AuthContext::class);                           // one per request / coroutine
$c->set('config.timeout', 30);                             // named value

$c->bind(MailerInterface::class, fn(Container $c) =>       // factory closure
    new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class)));

$c->contextual(LoggerInterface::class,                     // consumer-aware factory
    fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'));

// Resolution
$c->make(UserService::class);
$c->make(ImportJob::class, ['chunkSize' => 500]);          // overrides — always built fresh
$c->call(fn(UserService $s) => $s->all());                 // method / closure injection

// Injection without construction — the caller keeps the object's identity
$c->inject($repository);

// Ending a request scope where nothing ends it for you (a worker loop)
$c->flushRequestScope();
```

```php
#[Singleton]
class UserRepository {}

class SomeCommand
{
    #[Autowired]                       // by declared type
    private UserService $service;

    #[Inject('config.timeout')]        // named value
    private int $timeout;

    #[Lazy]                            // proxy now, resolved on first use
    private ReportBuilder $reports;
}
```

Full signatures, defaults and edge cases: [API reference](https://winterframe.net/packages/di/api-reference).

---

## Documentation

The user-facing documentation lives at **[winterframe.net/packages/di](https://winterframe.net/packages/di)**
(the link picks your language; RU and EN are both complete).

**Start here**

| Page | What it answers |
|------|-----------------|
| [Introduction](https://winterframe.net/packages/di/intro) | What the container is and whether you need it |
| [Installation](https://winterframe.net/packages/di/installation) | Requirements, install, optional `ext-swoole` |
| [Quick start](https://winterframe.net/packages/di/quickstart) | Bootstrap, auto-discovery, first resolved service |
| [Mental model](https://winterframe.net/packages/di/mental-model) | How to think about it — and why the default is `transient` |

**Guides**

| Page | What it answers |
|------|-----------------|
| [Service providers](https://winterframe.net/packages/di/service-providers) | Grouping bindings, interface → implementation, factories |
| [Scanning & auto-discovery](https://winterframe.net/packages/di/scanning-and-autodiscovery) | Registering classes without touching bootstrap; the scan cache |
| [Injecting into properties](https://winterframe.net/packages/di/injecting-into-properties) | `#[Autowired]` vs `#[Inject]`, inherited private properties |
| [Per-consumer logger](https://winterframe.net/packages/di/per-consumer-logger) | The classic `contextual()` recipe, end to end |
| [Breaking circular dependencies](https://winterframe.net/packages/di/breaking-circular-dependencies) | Reading the error chain, and where to put `#[Lazy]` |

**Reference**

| Page | What it answers |
|------|-----------------|
| [API reference](https://winterframe.net/packages/di/api-reference) | Every method, contract and exception |
| [Attributes](https://winterframe.net/packages/di/attributes) | All six attributes with their targets |
| [Scopes](https://winterframe.net/packages/di/scopes) | Lifetimes, precedence, the Swoole safety matrix |

**Deep dive**

| Page | What it answers |
|------|-----------------|
| [Resolution lifecycle](https://winterframe.net/packages/di/resolution-lifecycle) | What `make()` does, step by step |
| [Request scope & Swoole](https://winterframe.net/packages/di/request-scope-and-swoole) | Per-coroutine isolation, and the FPM/CLI fallback |
| [Concurrent resolution](https://winterframe.net/packages/di/concurrent-resolution) | Many requests, one container — stacks and singleton waiting |
| [Reflection cache](https://winterframe.net/packages/di/reflection-cache) | What is memoised per process, and the injection plan |
| [Lazy proxies](https://winterframe.net/packages/di/lazy-proxies) | Deferred resolution, and what a proxy cannot do |

Classes in this package carry an `@link` to their page, so the same documentation is one click
away from your IDE.

---

## Contributing

Internal technical notes — exact contracts, invariants, and the reasoning behind decisions that
are not obvious from the code — live in [`docs/`](docs/README.md). Read that before changing
resolution behaviour.

```bash
composer test        # phpunit
composer test-detail # phpunit --testdox
composer cs-check    # phpcs
composer cs-fix      # phpcbf
```

---

## License

MIT License. See [LICENSE](LICENSE).
