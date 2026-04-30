<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Marks a property or constructor parameter for automatic injection by type.
 *
 * Equivalent to #[Inject] with no arguments — the container resolves the
 * dependency from the declared PHP type.
 *
 * Use #[Inject('id')] when you need to inject a specific binding or named value.
 *
 * Example:
 * ```
 *   class UserController
 *   {
 *       #[Autowired]
 *       private UserService $service;
 *
 *       public function __construct(
 *           #[Autowired] private CacheInterface $cache,
 *       ) {}
 *   }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Autowired
{
}
