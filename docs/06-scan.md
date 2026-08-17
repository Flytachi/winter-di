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

The file is written to a temporary neighbour and renamed into place. Readers `require` it
without taking a lock, and several workers booting on a cold cache all write at once — an
in-place write is truncated when it is opened, so a reader would `require` an empty file
(the cache silently stops working) or a partial one (a parse error it cannot catch). A
measured half of concurrent reads saw exactly that before the rename was introduced.
`opcache_invalidate()` follows the rename, since `validate_timestamps=0` — the usual
production setting — would otherwise keep serving the previously compiled file.

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

## Recognising a class

Files are **tokenised**, not pattern-matched. A missed class is registered nowhere — no
binding, no routes, no discovery of any kind — and nothing reports it: the controller
simply has no routes, the service simply is not in the container. That silence is why the
parsing is exact rather than approximately right.

Recognised regardless of formatting:

```php
#[Attr] class Foo {}            // an attribute sharing the line
#[Attr] final class Foo {}
namespace App { class Foo {} }  // indented inside a braced namespace
class First {} class Second {}  // every class in the file, not only the first
```

Not mistaken for declarations:

```php
/* class Ghost */               // block comments
/** class Ghost */              // docblocks
$sql = 'class Ghost';           // strings and heredocs
Other::class                    // the ::class constant
new class {}                    // anonymous classes have no name
```

Only `class` is collected — interfaces, traits and enums are not, and abstract classes are
dropped later by the dispatcher.

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

Both sides of the comparison are resolved with `realpath()` before matching. The paths
being tested come from `getRealPath()`, which follows symlinks, so an application served
through one — `current -> releases/2026…`, the usual deploy layout — is excluded
correctly instead of being walked in full.

Directories worth excluding beyond `vendor/`: anywhere the application **writes** PHP
(generated proxies, caches) and anywhere it keeps **templates**, since a view that happens
to declare a helper class would otherwise be discovered and required.

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
- Class extraction tokenises each file. That costs roughly **35 µs more per file** than a
  regular expression — about 11 ms on a 300-class project — and buys exactness: see
  [Recognising a class](#recognising-a-class) for what a pattern cannot see.
- The cost is paid on a **cold scan only**. With a warm cache the filesystem is not
  touched at all: measured on a small application, 58.9 ms cold against 4.4 ms warm.
- Collector dispatch is O(classes × collectors) with no internal caching — keep collectors lightweight.
