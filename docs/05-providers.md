# Service Providers

A `ServiceProvider` groups related bindings — especially interface → implementation mappings
and factory closures — in one place instead of scattering them across bootstrap.

---

## Creating a provider

Extend `ServiceProvider` and implement `register()`:

```php
use Flytachi\Winter\DI\Contract\ServiceProvider;
use Flytachi\Winter\DI\Container;

class AppServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->singleton(CacheInterface::class, RedisCache::class);

        $c->bind(MailerInterface::class, fn(Container $c) =>
            new SmtpMailer(
                host: env('MAIL_HOST', 'localhost'),
                logger: $c->make(LoggerInterface::class),
            )
        );

        $c->set('config.timeout', (int) env('APP_TIMEOUT', 30));
    }
}
```

---

## Registering a provider

```php
// bootstrap.php
Container::init()
    ->register(AppServiceProvider::class)
    ->register(DatabaseServiceProvider::class)
    ->register(QueueServiceProvider::class);
```

Providers are executed immediately in registration order.

---

## Splitting by domain

```php
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->singleton(Connection::class, fn() =>
            new PdoConnection(env('DB_DSN'), env('DB_USER'), env('DB_PASS'))
        );
        $c->singleton(UserRepository::class);
        $c->singleton(OrderRepository::class);
    }
}

class AuthServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->request(AuthContext::class);
        $c->singleton(TokenValidator::class, JwtTokenValidator::class);
        $c->set('auth.secret', env('JWT_SECRET'));
    }
}
```

---

## Recipe: per-consumer logger (`contextual`)

A logger that names itself after the class it is injected into — no
`getLogger(self::class)` boilerplate in every class. Register the overlay once
in a provider (or the framework bootstrap):

```php
use Psr\Log\LoggerInterface;
use Flytachi\Winter\Logger\LoggerFactory;

class LoggingServiceProvider extends ServiceProvider
{
    public function register(Container $c): void
    {
        $c->contextual(
            LoggerInterface::class,
            fn(Container $c, ?string $consumer) => LoggerFactory::getLogger($consumer ?? 'app'),
        );
    }
}
```

Then any class gets its own channel-named logger by type:

```php
class MainController
{
    #[Autowired]
    private LoggerInterface $logger;          // → LoggerFactory::getLogger(MainController::class)

    public function index(): void
    {
        $this->logger->info('hit');            // (MainController): hit
    }
}
```

The factory is consumer-aware (`$consumer` = the injected-into class) and runs
per injection — `LoggerFactory` already caches per `channel:class`, so this is
cheap even in long-running Swoole workers. To **override** a default registered
earlier (e.g. by the framework), just call `contextual(LoggerInterface::class, …)`
again with your own factory.

---

## Providers vs attributes

| Situation | Recommended approach |
|-----------|---------------------|
| Concrete class, owns its scope | `#[Singleton]` / `#[Request]` / `#[Transient]` |
| Interface → implementation | `ServiceProvider::register()` |
| Factory with runtime config | `ServiceProvider::register()` with closure |
| Per-consumer instance (e.g. logger) | `$c->contextual()` in provider |
| Vendor / third-party class | `ServiceProvider::register()` |
| Named scalar value | `$c->set()` in provider |
