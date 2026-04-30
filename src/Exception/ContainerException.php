<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
