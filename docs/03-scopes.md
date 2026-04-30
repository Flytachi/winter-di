# Scopes

A scope defines how long a resolved instance lives and whether it is shared.

---

## `singleton`

One instance per container (= per process) lifetime.

```php
// Via attribute
#[Singleton]
class DatabaseConnection { }

// Via registration
$c->singleton(CacheInterface::class, RedisCache::class);
```

**Lifecycle:** created on the first `make()`, cached, returned on every subsequent call.

**Use for:** stateless services — repositories, factories, connection pools, config readers.

**Avoid for:** classes that hold per-request state (auth user, request data) — use `request` instead.

---

## `transient`

A new instance is created on every `make()` / injection.

```php
#[Transient]
class QueryBuilder { }

$c->transient(ReportBuilder::class);
```

**Lifecycle:** no caching — every call to `make()` returns a fresh object.

**Use for:** stateful objects that must not be shared — query builders, form objects, DTOs, unit-of-work.

**Default scope** when no attribute and no manual registration is set.

---

## `request`

One instance per HTTP request / coroutine.

```php
#[Request]
class AuthContext { }

$c->request(UnitOfWork::class);
```

**Lifecycle:**

| Runtime | Behaviour |
|---------|-----------|
| **Swoole** | Stored in `Coroutine::getContext()['__di']` — fully isolated per coroutine. Each concurrent request gets its own instance. Cleaned up automatically when the coroutine ends. |
| **FPM** | Equivalent to `singleton` — one request = one process, so process-level cache is safe. |
| **CLI** | Equivalent to `singleton` — one command = one process. |

**Use for:** classes that carry per-request state — auth context, current user, unit of work, request-bound counters.

---

## Scope priority

Manual registration always overrides the class attribute:

```php
#[Singleton]
class UserService { }

// Override to transient for a specific context (e.g. tests)
$c->transient(UserService::class);
// → UserService is now transient regardless of #[Singleton]
```

---

## Swoole safety guide

| Class type | Recommended scope |
|------------|-------------------|
| DB connection pool | `singleton` |
| Repository (stateless) | `singleton` |
| Auth context | `request` |
| Current user | `request` |
| Unit of work | `request` |
| Query builder | `transient` |
| DTO / value object | `transient` |

Never put mutable per-request data into a `singleton` in Swoole — it leaks across concurrent requests.
