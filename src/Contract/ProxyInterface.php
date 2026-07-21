<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Contract;

/**
 * Implemented by generated subclasses that stand in for another class.
 *
 * A proxy is registered in the container under the name of the class it
 * replaces, which leaves the resolver with a problem: `$instance::class` is the
 * generated name, not the one the application wrote. That name leaks into every
 * consumer-aware decision — most visibly into
 * {@see \Flytachi\Winter\DI\Container::contextual()} factories, where a logger
 * would end up named after the proxy instead of the service.
 *
 * Declaring the target restores the original identity: the resolver builds the
 * injection plan from the target class and passes the target name as the
 * consumer.
 *
 * ---
 * ### Example
 *
 * ```
 * final class UserService__Async extends UserService implements ProxyInterface
 * {
 *     public static function proxyTarget(): string
 *     {
 *         return UserService::class;
 *     }
 * }
 * ```
 *
 * Implementations are expected to extend their target, so the instance stays
 * type-compatible with everything that depends on it.
 */
interface ProxyInterface
{
    /**
     * Returns the class this proxy stands in for.
     *
     * @return class-string
     */
    public static function proxyTarget(): string;
}
