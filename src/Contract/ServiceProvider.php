<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Contract;

use Flytachi\Winter\DI\Container;

/**
 * Base class for grouping container bindings.
 *
 * Extend this class to organise interface → implementation mappings,
 * factory closures, and named values. Register providers in bootstrap:
 *```
 *   Container::init()
 *       ->register(AppServiceProvider::class)
 *       ->register(DatabaseServiceProvider::class);
 * ```
 *
 * Example provider:
 * ```
 *   class AppServiceProvider extends ServiceProvider
 *   {
 *       public function register(Container $c): void
 *       {
 *           $c->singleton(CacheInterface::class, RedisCache::class);
 *           $c->bind(MailerInterface::class, fn($c) =>
 *               new SmtpMailer(env('MAIL_HOST'), $c->make(LoggerInterface::class))
 *           );
 *           $c->set('config.timeout', 30);
 *       }
 *   }
 * ```
 */
abstract class ServiceProvider
{
    abstract public function register(Container $c): void;
}
