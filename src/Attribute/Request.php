<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Attribute;

use Attribute;

/**
 * Marks a class as request-scoped.
 *
 * One instance per HTTP request / coroutine.
 * In Swoole — isolated per coroutine via Coroutine::getContext().
 * In FPM / CLI — equivalent to singleton (one request = one process).
 *
 * Use for classes that hold per-request state: auth context, request data, unit of work.
 *
 * Example:
 * ```
 *   #[Request]
 *   class AuthContext { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Request
{
}
