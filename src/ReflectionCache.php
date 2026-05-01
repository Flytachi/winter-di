<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI;

use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Per-process reflection cache.
 *
 * Creates each reflection object once and reuses it for the process lifetime.
 * Critical for Swoole workers — reflection is expensive and requests share memory.
 *
 * ```
 * $ref    = ReflectionCache::classOf(UserService::class);
 * $enum   = ReflectionCache::enumOf(Status::class);
 * $method = ReflectionCache::method(UserService::class, 'handle');
 * $params = ReflectionCache::parameters(UserService::class, 'handle');
 * ```
 */
final class ReflectionCache
{
    /** @var array<string, ReflectionClass<object>> */
    private static array $classes = [];

    /** @var array<string, ReflectionEnum<object>> */
    private static array $enums = [];

    /** @var array<string, ReflectionMethod> */
    private static array $methods = [];

    /** @var array<string, list<ReflectionParameter>> */
    private static array $parameters = [];

    /** @return ReflectionClass<object> */
    public static function classOf(string $class): ReflectionClass
    {
        return self::$classes[$class] ??= new ReflectionClass($class);
    }

    /** @return ReflectionEnum<object> */
    public static function enumOf(string $class): ReflectionEnum
    {
        return self::$enums[$class] ??= new ReflectionEnum($class);
    }

    public static function method(string $class, string $method): ReflectionMethod
    {
        return self::$methods[$class . '::' . $method] ??= new ReflectionMethod($class, $method);
    }

    /** @return list<ReflectionParameter> */
    public static function parameters(string $class, string $method): array
    {
        return self::$parameters[$class . '::' . $method]
            ??= self::method($class, $method)->getParameters();
    }
}
