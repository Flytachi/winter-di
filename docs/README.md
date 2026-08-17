# winter-di — internal reference

Technical documentation for the container itself: exact API contracts, the rules the
resolver follows, and the reasoning behind decisions that are not obvious from the code.

This is **not** the getting-started guide — that is the [root README](../README.md), which
shows the shortest path to a working container. These pages assume you already have one
and now need to know precisely what happens, or why it happens that way. Written for the
people who maintain the framework, debug a resolution that went wrong, or build on top of
the container (collectors, proxies, scope-aware infrastructure).

---

## Map

| # | Page | Go here when |
|---|------|--------------|
| 01 | [Overview](01-overview.md) | You want the feature list and a bootstrap that runs |
| 02 | [Container API](02-container.md) | You need the exact signature and semantics of one method |
| 03 | [Scopes](03-scopes.md) | Choosing a lifetime, or a bean is shared/rebuilt when it should not be |
| 04 | [Attributes](04-attributes.md) | Declaring scope or injection in the class instead of bootstrap |
| 05 | [Service providers](05-providers.md) | Grouping bindings; interface → implementation; per-consumer factories |
| 06 | [Scanner](06-scan.md) | Class discovery, collectors, the scan cache, excluding directories |
| 07 | [ReflectionCache](07-reflection-cache.md) | Reflection reuse and the cached injection plan — the hot path |
| 08 | [Proxies](08-proxies.md) | A generated subclass must resolve under the identity it stands for |

---

## Routes through it

- **"Which scope do I give this class?"** → [03](03-scopes.md), the table at the end, then
  *Never hold a shorter-lived scope* — the mistake that costs the most.
- **"Why did I get somebody else's data?"** → [03](03-scopes.md): a `#[Singleton]` holding
  a `#[Request]` bean freezes the first request's instance for the worker's lifetime.
- **"Circular dependency detected" and there is no cycle** → [03 → Concurrent
  resolution](03-scopes.md#concurrent-resolution). Real cycles: `#[Lazy]` in [04](04-attributes.md).
- **"My class was not discovered"** → [06](06-scan.md): what counts as a declaration, what
  the cache holds, which directories are excluded.
- **"How do I inject into an object I built myself?"** → `inject()` in [02](02-container.md).
- **"A logger named after its consumer"** → `contextual()` in [02](02-container.md), recipe
  in [05](05-providers.md).
- **"Where does the time go?"** → [07](07-reflection-cache.md): reflection objects and the
  injection plan are cached per process; only `setValue()` and lookups are paid per resolve.

---

## Invariants these pages rely on

Everything else is detail; break one of these and the rest stops being true.

1. **Manual registration beats attributes** — and re-registering drops everything the
   previous scope left behind (cached instance, flush-list entry), so an override also
   applies to an already-resolved class.
2. **The default scope is `transient`** — no attribute, no registration, new object.
3. **A resolution stack belongs to one unit of work** — a coroutine under Swoole, the
   process elsewhere. Cycle detection is per unit; it never sees another request's build.
   Waiting is only ever done for a build whose result gets cached, and each builder wakes
   its own waiters — see [Scopes → Concurrent resolution](03-scopes.md#concurrent-resolution).
4. **`singleton` means one per worker process** — not one per application, and not one per
   coroutine.
5. **Injected properties are resolved once, when the holder is built** — which is why a
   long-lived holder must not capture a shorter-lived dependency.

---

## Keeping it honest

Every code sample here is meant to run as written against the current `src/`. When you
change behaviour, the page that describes it is part of the change — a sample that no
longer executes is worse than no sample, because it is trusted. Claims about measurable
things (cost, cache hits, what happens under concurrency) belong in a test, and the page
should say which one.
