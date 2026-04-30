<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Collector;

use Flytachi\Winter\DI\Attribute\Request;
use Flytachi\Winter\DI\Attribute\Singleton;
use Flytachi\Winter\DI\Attribute\Transient;
use Flytachi\Winter\DI\Container;
use Flytachi\Winter\DI\Contract\CollectorInterface;
use ReflectionClass;

/**
 * Built-in collector — registers classes annotated with DI scope attributes.
 *
 * Registers:
 *   #[Singleton] → $container->singleton($class)
 *   #[Request]   → $container->request($class)
 *   #[Transient] → $container->transient($class)
 *
 * Usage:
 *   Scanner::run($rootDir)->collect(new DICollector($container))->execute();
 */
final class DICollector implements CollectorInterface
{
    public function __construct(private readonly Container $container) {}

    public function collect(string $class, ReflectionClass $ref): void
    {
        if (!empty($ref->getAttributes(Singleton::class))) {
            $this->container->singleton($class);
        } elseif (!empty($ref->getAttributes(Request::class))) {
            $this->container->request($class);
        } elseif (!empty($ref->getAttributes(Transient::class))) {
            $this->container->transient($class);
        }
    }
}
