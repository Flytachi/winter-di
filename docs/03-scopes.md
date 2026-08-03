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

### Long-lived workers have no boundary

The table above holds where a process *is* a request. A worker is not: its whole body runs
inside **one** coroutine, so a request-scoped bean resolved in the loop lives for the
entire run and hands each job the previous job's state.

```
iteration 1: object #61, at entry saw "unset"
iteration 2: object #61, at entry saw "job-1"    ← the last job's data
iteration 3: object #61, at entry saw "job-2"
```

Only the code driving the loop knows where one unit of work ends, so the boundary is
declared with [`flushRequestScope()`](02-container.md#flushrequestscope-void):

```php
while ($this->isRunning()) {
    $job = $queue->pop();

    $container->flushRequestScope();            // ← this job is a new unit
    $ctx = $container->make(JobContext::class);  // fresh, every time
    // ... work ...
}
```

Singletons are untouched — a pool, a warm cache or a counter is not scoped to a unit and
keeps both identity and state.

---

## Never hold a shorter-lived scope

One rule covers every combination:

> **A class may hold a reference to a shorter-lived object only if it does not outlive it.**

Injected properties are resolved **once, when the holder is built**. A `#[Singleton]` is
built once per worker, so a `#[Request]` bean captured then belongs to whichever request
came first — and every later request keeps seeing it:

```php
#[Request]
class AuthContext { /* ... */ }

#[Singleton]
class OrderService
{
    #[Autowired] private AuthContext $context;   // ← the first request's, forever
}
```

Nothing throws and nothing is logged; with an authentication context in that position,
every user after the first is served under the first user's identity. The reach is
transitive — a singleton freezes its whole dependency subtree, so
`#[Singleton] → plain service → #[Request]` leaks identically.

Fixes, in order of preference:

- drop `#[Singleton]` from the holder, so it is built per request (`transient` is the default);
- resolve the request-scoped bean where it is used, not as a property;
- make the dependency stateless and give it `#[Singleton]` too.

> The Winter kernel refuses to boot on this combination and names the offending path. The
> container alone does not — it cannot know whether a given resolution is a mistake.

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
