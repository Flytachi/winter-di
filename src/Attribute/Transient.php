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
 *   #[Transient]
 *   class QueryBuilder { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Transient
{
}
