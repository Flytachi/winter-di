<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Marks a class as singleton-scoped.
 *
 * One instance per container lifetime (process).
 * Safe for stateless services: database connections, repositories, factories.
 *
 * NOT safe for Swoole if the class holds per-request state — use #[Request] instead.
 *
 * Example:
 * ```
 *   #[Singleton]
 *   class UserService { ... }
 * ```
 *
 * @link https://winterframe.net/packages/di/scopes#singleton One per process — and when that is the wrong choice
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Singleton
{
}
