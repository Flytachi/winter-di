# Directory Scan

`scan()` walks a directory tree, finds PHP classes annotated with `#[Singleton]`,
`#[Request]`, or `#[Transient]`, and registers them automatically.
This eliminates manual registration for every concrete class in the project.

---

## Usage

```php
Container::init()
    ->scan(Kernel::$pathRoot);          // scan entire project root
```

```php
Container::init()
    ->scan(Kernel::$pathRoot, [         // exclude additional directories
        Kernel::$pathRoot . '/legacy',
        Kernel::$pathRoot . '/generated',
    ]);
```

The `vendor/` directory is **always excluded** automatically.

---

## What gets registered

Only classes with one of the three scope attributes are registered:

```php
#[Singleton]  → $container->singleton(ClassName::class)
#[Request]    → $container->request(ClassName::class)
#[Transient]  → $container->transient(ClassName::class)
```

Classes without any scope attribute are **not** registered by scan — they are still
resolvable via autowiring on first `make()`, just not pre-registered.

---

## Scan + providers together

`scan()` handles concrete classes; providers handle interface bindings.
Use both together:

```php
Container::init()
    ->scan(Kernel::$pathRoot)               // auto-register #[Singleton] etc.
    ->register(AppServiceProvider::class);  // bind interfaces + factories
```

---

## Manual registration overrides scan

If a class is found by scan AND registered manually, the manual registration wins:

```php
#[Singleton]
class UserService { }

// This overrides the scanned #[Singleton] — UserService becomes transient:
$container->transient(UserService::class);
```

---

## Performance

Scan reads each `.php` file once per bootstrap using a fast regex —
it does not load or `require` the file, only extracts the `namespace` and `class` name.
`class_exists()` triggers autoloading only if the extracted FQCN passes the name check.

For production, scan runs once when the process starts (FPM worker boot / Swoole server start)
and the result lives in memory for the process lifetime — no per-request overhead.
