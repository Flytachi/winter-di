# Scanner

`Scanner` walks a project tree once and dispatches every discovered PHP class to all
registered `CollectorInterface` implementations. Multiple collectors share a single
filesystem pass — no duplicate directory walks.

---

## Basic usage

```php
use Flytachi\Winter\DI\Scanner;
use Flytachi\Winter\DI\Collector\DICollector;

$container = Container::init();

Scanner::run(__DIR__)
    ->collect(new DICollector($container))
    ->execute();
```

---

## With cache (production)

```php
Scanner::run($rootDir, cache: '/var/cache/di/scanner.php')
    ->collect(new DICollector($container))
    ->collect(new MappingCollector($router))
    ->execute();
```

- **Cache hit** — loads the FQCN list from the PHP file, skips the FS walk entirely.
- **Cache miss** — walks the filesystem, writes the result, dispatches to collectors.

The cache is a plain PHP file returning a `string[]` of FQCNs:

```php
<?php
return [
    'App\\Service\\UserService',
    'App\\Controller\\UserController',
    // ...
];
```

Delete the file to force a full rescan on next boot.

---

## Without cache (dev / non-DI collectors)

```php
Scanner::run($rootDir)
    ->collect(new PpaCollector())
    ->collect(new CmdCollector())
    ->execute();
```

No file is ever read or written — always performs the FS walk. Use this for collectors
that must run on every boot (commands, annotations, route maps that aren't cached separately).

---

## Excluding directories

`vendor/` is always excluded automatically. Add more paths via `exclude()`:

```php
Scanner::run($rootDir)
    ->exclude([
        $rootDir . '/legacy',
        $rootDir . '/generated',
    ])
    ->collect(new DICollector($container))
    ->execute();
```

---

## Multiple collectors

All collectors registered via `collect()` receive every class from the same single scan:

```php
Scanner::run($rootDir, cache: $cachePath)
    ->collect(new DICollector($container))     // registers scope attributes
    ->collect(new MappingCollector($router))   // extracts route attributes
    ->collect(new ExceptionCollector())        // maps exception handlers
    ->execute();
```

Collectors are called in registration order for each class.

---

## Implementing a custom collector

```php
use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;

final class RouteCollector implements CollectorInterface
{
    public function __construct(private readonly Router $router) {}

    public function collect(string $class, ReflectionClass $ref): void
    {
        $attrs = $ref->getAttributes(Route::class);
        foreach ($attrs as $attr) {
            $route = $attr->newInstance();
            $this->router->add($route->method, $route->path, $class);
        }
    }
}
```

The scanner skips abstract classes, interfaces, and traits before calling collectors —
`$ref->isAbstract()`, `$ref->isInterface()`, and `$ref->isTrait()` are never true inside `collect()`.

---

## What Scanner does NOT do

- It does not register bindings itself — that is the collector's job.
- It does not cache collector results — only the list of discovered FQCNs.
- It does not load every file unconditionally — `require_once` is only called when
  `class_exists()` returns false (needed for non-autoloaded paths such as test fixtures).

---

## Performance

- One `RecursiveIteratorIterator` pass over the project tree.
- Class extraction uses two fast regexes (namespace + class name) — no AST parsing.
- Production cache eliminates the FS walk on every boot after the first.
- Collector dispatch is O(classes × collectors) with no internal caching — keep collectors lightweight.
