<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Overrides autowiring for a specific constructor parameter or class property.
 *
 * Without argument — resolves by the declared type (same as autowiring, explicit marker):
 * ```
 *   public function __construct(
 *       #[Inject] private CacheInterface $cache,
 *   ) {}
 * ```
 *
 * With a class string — injects a specific implementation, ignoring global bindings:
 * ```
 *   public function __construct(
 *       #[Inject(FileCache::class)] private CacheInterface $fallback,
 *   ) {}
 *```
 *
 * With a named binding key — injects a named value registered in the container:
 * ```
 *   public function __construct(
 *       #[Inject('config.timeout')] private int $timeout,
 *   ) {}
 * ```
 *
 * On a property — injects after construction (when constructor injection is not possible):
 * ```
 *   #[Inject(RedisCache::class)]
 *   private CacheInterface $cache;
 *```
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Inject
{
    /**
     * @param string|null $id  What to resolve from the container:
     *                         - null              — resolve by the declared PHP type (default)
     *                         - FQCN string       — inject a specific class, bypassing global bindings:
     *                                               #[Inject(RedisCache::class)]
     *                         - named binding key — inject a scalar or pre-built instance
     *                                               registered via Container::set():
     *                                               #[Inject('config.timeout')]
     */
    public function __construct(
        public ?string $id = null,
    ) {
    }
}
