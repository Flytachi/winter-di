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

## Concurrent resolution

One Swoole worker resolves for many requests at once, so the container has to tell "this
class is being built **by me**" from "by somebody else". Both answers live per unit of
work: the resolution stack sits in `Coroutine::getContext()['__di_building']`, next to the
request-scope cache, and falls back to a plain property off the coroutine path, where a
process handles one unit at a time.

| Scope | Two coroutines resolve the same class at once |
|-------|-----------------------------------------------|
| `transient` | Each builds its own instance — that is the contract |
| `request` | Each has its own instance by definition; nothing is shared |
| `singleton` | The first builds it, the rest **wait** and receive that same instance |

Waiting only ever happens on the first resolution of a singleton, while the cache is still
empty: afterwards `make()` returns from the cache before any of this machinery runs. It
costs nothing either — a waiter wakes no later than it would have finished building its
own copy — and it is what keeps a factory's side effects (opening a connection, warming a
cache) from running twice on a cold worker.

Two details keep that promise honest:

- **Only a build whose result is cached is worth waiting for.** `make($abstract, $overrides)`
  is never cached, so no channel is created for it — a waiter would otherwise sleep through
  the whole build, find an empty cache, and build its own copy anyway.
- **A builder wakes its own waiters.** Two builds of the same class can overlap and the
  second replaces the first in the pending map; each closes the channel it created, and
  removes the map entry only while it is still its own. Closing whatever happens to be in
  the map instead would leave the other build's waiters asleep until the bound expires.

This matters because a resolution can pause: a factory that opens a Redis connection under
`SWOOLE_HOOK_ALL` yields mid-build. With a stack shared by the whole worker, every other
request resolving that class would have seen it as "already being built" and failed with a
circular dependency that does not exist.

A genuine cycle is still caught — inside the coroutine that has it — and the message names
the whole chain:

```
Circular dependency detected while resolving [App\A] → [App\B] → [App\A].
```

---

## Scope priority

Manual registration always overrides the class attribute — including for a class that has
already been resolved, since re-registering drops both what the previous scope cached and
its place on the request-scope flush list:

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
