# ReflectionCache

`ReflectionCache` is a per-process cache for PHP reflection objects.

Each `ReflectionClass`, `ReflectionEnum`, `ReflectionMethod`, and `ReflectionParameter[]` is created
once on first access and reused for the entire process lifetime. In Swoole workers,
where a single process handles thousands of requests, this eliminates repeated
reflection construction on the hot path.

---

## API

```php
use Flytachi\Winter\DI\ReflectionCache;
```

### `classOf(string $class): ReflectionClass`

Returns a cached `ReflectionClass` for the given FQCN.

```php
$ref = ReflectionCache::classOf(UserService::class);

$ref->getProperties();   // ReflectionProperty[]
$ref->getConstructor();  // ReflectionMethod|null
$ref->getAttributes();   // ReflectionAttribute[]
```

### `enumOf(string $enum): ReflectionEnum`

Returns a cached `ReflectionEnum` for the given FQCN.

```php
$ref = ReflectionCache::enumOf(UserService::class);

$ref->getBackingType();   // ReflectionNamedType|null
```

### `method(string $class, string $method): ReflectionMethod`

Returns a cached `ReflectionMethod` for `$class::$method`.

```php
$ref = ReflectionCache::method(UserService::class, 'handle');

$ref->getParameters();   // ReflectionParameter[]
$ref->invoke($instance, ...$args);
```

### `parameters(string $class, string $method): ReflectionParameter[]`

Returns the cached parameter list for `$class::$method`.
Delegates to `method()` internally — both calls share the same method cache entry.

```php
$params = ReflectionCache::parameters(UserService::class, 'handle');

foreach ($params as $param) {
    $param->getName();          // parameter name
    $param->getType();          // ReflectionNamedType|null
    $param->getAttributes();    // #[Inject], #[PathVariable], etc.
    $param->isDefaultValueAvailable();
    $param->getDefaultValue();
}
```

---

## Why it matters in Swoole

In FPM each request is a new process — reflection is cheap (per-process cost
amortised across one request). In Swoole a single worker process handles thousands
of concurrent requests, so the cost of `new ReflectionClass()` and
`new ReflectionMethod()` would accumulate linearly without caching.

`ReflectionCache` ensures the reflection graph is built once per worker, regardless
of request count:

```
FPM worker:    1 request  → 1 reflection build (no difference)
Swoole worker: N requests → 1 reflection build (N-1 cache hits)
```

---

## Internal usage

`ReflectionResolver` (the DI resolution engine) uses `ReflectionCache` in three places:

| Method | Cache call |
|--------|-----------|
| `resolve()` → `constructorParams()` | `classOf($class)->getConstructor()` |
| `call()` | `method($instance::class, $method)` |
| `injectProperties()` | `classOf($instance::class)->getProperties()` |

---

## External usage

`ReflectionCache` is a public utility — frameworks and libraries that perform
reflection-based parameter resolution (e.g. HTTP controllers, CLI dispatchers)
can use the same cache instead of maintaining their own:

```php
// HTTP parameter resolver — reads #[PathVariable], #[RequestBody], etc.
$params = ReflectionCache::parameters($controllerClass, $methodName);
foreach ($params as $param) {
    if ($attr = $param->getAttributes(PathVariable::class)[0] ?? null) {
        // resolve path variable
    }
}

// Route dispatcher — invoke a controller method
$method = ReflectionCache::method($controllerClass, $action);
$method->invokeArgs($controller, $resolvedArgs);
```

---

## Thread safety

`ReflectionCache` uses `static` arrays. In PHP-FPM and CLI each process has its
own memory — no sharing, no locking needed. In Swoole, all coroutines in a worker
share the same process memory, but reflection objects are read-only after creation,
so concurrent reads are safe without locking.
