<?php

declare(strict_types=1);

namespace Flytachi\Winter\DI\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
