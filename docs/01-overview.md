# winter-di — Overview

**winter-di** is a lightweight PSR-11 dependency injection container for the Winter framework.
It provides autowiring, three lifecycle scopes, attribute-based configuration, and service providers.

---

## Features

- **PSR-11** compliant (`ContainerInterface`)
- **Autowiring** — constructor parameters resolved automatically by type
- **Three scopes** — `singleton`, `transient`, `request`
- **Attributes** — `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Autowired]`, `#[Inject]`, `#[Lazy]`
- **Lazy injection** — `#[Lazy]` injects a native PHP 8.4 proxy; resolves on first use (breaks circular deps, like Spring `@Lazy`)
- **Directory scan** — `Scanner` auto-discovers and registers annotated classes; files are
  tokenised, so a class is found whatever its formatting and is never confused with the
  same words in a comment or a string
- **Injection without construction** — `inject()` fills an object you built yourself, for
  the cases where the caller must own the object's identity
- **Explicit scope boundaries** — `flushRequestScope()` ends a request scope where nothing
  ends it for you, such as a worker looping over jobs
- **Service providers** — group related bindings in one place
- **Contextual binding** — `contextual()` factories receive the consuming class (e.g. per-class loggers)
- **Method injection** — `call()` resolves parameters of any callable
- **Property injection** — `#[Autowired]` / `#[Inject]` on class properties, inherited private ones included
- **Proxy-aware** — `ProxyInterface` keeps a generated subclass resolving under the identity it stands for
- **Circular dependency detection** — throws on cycles
- **Swoole-safe** — `request` scope uses `Coroutine::getContext()` for isolation
- **ReflectionCache** — per-process cache for `ReflectionClass` / `ReflectionMethod` / `ReflectionParameter[]`

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

## Documentation index

| # | File | Contents |
|---|------|----------|
| 01 | [01-overview.md](01-overview.md) | Features, installation, quick start |
| 02 | [02-container.md](02-container.md) | Container API — init, make, call, bind, contextual, set |
| 03 | [03-scopes.md](03-scopes.md) | Scopes — singleton, transient, request; Swoole behaviour |
| 04 | [04-attributes.md](04-attributes.md) | `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Autowired]`, `#[Inject]` |
| 05 | [05-providers.md](05-providers.md) | ServiceProvider — grouping bindings |
| 06 | [06-scan.md](06-scan.md) | Scanner — directory scan, collectors, cache |
| 07 | [07-reflection-cache.md](07-reflection-cache.md) | ReflectionCache — per-process reflection object cache, injection plan |
| 08 | [08-proxies.md](08-proxies.md) | ProxyInterface — generated subclasses that stand in for a service |
