<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Marks a property or constructor parameter for lazy injection.
 *
 *  Instead of resolving the dependency immediately, the container injects a
 *  native lazy proxy (PHP 8.4 {@see \ReflectionClass::newLazyProxy()}) the real
 *  instance is resolved from the container on first access. The Spring "@Lazy"
 *  equivalent — its primary use is breaking circular dependencies (only one side
 *  of the cycle needs to be lazy).
 *
 *  Combine with the declared type or with #[Inject(Concrete::class)] — the proxy
 *  needs a concrete class to stand in for; an interface without a concrete throws.
 *
 * Example:
 * ```
 *   class SmsSendService
 *   {
 *       #[Lazy]
 *       private FakeSendService $peer;          // proxy — make() deferred to first use
 *
 *       public function __construct(
 *           #[Inject(SmsSendService::class), Lazy] private SendInterface $peer,
 *       ) {}
 *   }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Lazy
{
}
