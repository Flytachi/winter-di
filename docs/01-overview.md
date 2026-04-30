# winter-di — Overview

**winter-di** is a lightweight PSR-11 dependency injection container for the Winter framework.
It provides autowiring, three lifecycle scopes, attribute-based configuration, and service providers.

---

## Features

- **PSR-11** compliant (`ContainerInterface`)
- **Autowiring** — constructor parameters resolved automatically by type
- **Three scopes** — `singleton`, `transient`, `request`
- **Attributes** — `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Inject]`
- **Directory scan** — auto-discovers and registers annotated classes
- **Service providers** — group related bindings in one place
- **Method injection** — `call()` resolves parameters of any callable
- **Property injection** — `#[Inject]` on class properties
- **Circular dependency detection** — throws on cycles
- **Swoole-safe** — `request` scope uses `Coroutine::getContext()` for isolation

---

## Installation

```bash
composer require flytachi/winter-di
```

---

## Quick start

```php
use Flytachi\Winter\DI\Container;

// 1. Bootstrap (once, in bootstrap.php)
$container = Container::init()
    ->scan(__DIR__ . '/src')
    ->register(AppServiceProvider::class);

// 2. Resolve anywhere
$service = $container->make(UserService::class);

// 3. Call a method with injection
$result = $container->call([UserController::class, 'index']);

// 4. Get the container statically
$container = Container::getInstance();
```

---

## Documentation index

| # | File | Contents |
|---|------|----------|
| 01 | [01-overview.md](01-overview.md) | Features, installation, quick start |
| 02 | [02-container.md](02-container.md) | Container API — init, make, call, bind, set |
| 03 | [03-scopes.md](03-scopes.md) | Scopes — singleton, transient, request; Swoole behaviour |
| 04 | [04-attributes.md](04-attributes.md) | `#[Singleton]`, `#[Transient]`, `#[Request]`, `#[Inject]` |
| 05 | [05-providers.md](05-providers.md) | ServiceProvider — grouping bindings |
| 06 | [06-scan.md](06-scan.md) | Directory scan — auto-discovery |
