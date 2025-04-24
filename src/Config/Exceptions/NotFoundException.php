<?php

declare(strict_types=1);

namespace Src\Config\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception for when a requested entry cannot be found
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}