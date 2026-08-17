<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Marks a class as transient-scoped.
 *
 * A new instance is created on every make() / injection.
 * Safe everywhere. Use for stateful objects: query builders, DTOs, form objects.
 *
 * Example:
 * ```
 *   #[Transient]
 *   class QueryBuilder { ... }
 * ```
 *
 * @link https://winterframe.net/packages/di/scopes#transient The default lifetime and what belongs in it
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Transient
{
}
