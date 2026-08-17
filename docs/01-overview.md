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
- **Directory scan** — `Scanner` discovers classes and hands them to collectors
  (`DICollector` is the one that registers scope attributes); files are tokenised, so a
  class is found whatever its formatting and is never confused with the same words in a
  comment or a string
- **Injection without construction** — `inject()` fills an object you built yourself, for
  the cases where the caller must own the object's identity
- **Explicit scope boundaries** — `flushRequestScope()` ends a request scope where nothing
  ends it for you, such as a worker looping over jobs
- **Service providers** — group related bindings in one place
- **Contextual binding** — `contextual()` factories receive the consuming class (e.g. per-class loggers)
- **Method injection** — `call()` resolves parameters of any callable
- **Property injection** — `#[Autowired]` / `#[Inject]` on class properties, inherited private ones included
- **Proxy-aware** — `ProxyInterface` keeps a generated subclass resolving under the identity it stands for
- **Circular dependency detection** — throws on cycles, naming the whole chain (`A → B → A`)
- **Swoole-safe** — `request` scope and the resolution stack live in `Coroutine::getContext()`;
  a singleton wanted by two coroutines at once is built once, the second waits
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

The map of these pages, with a route for each common question, lives in
[docs/README.md](README.md) — kept in one place so the two cannot drift apart.
