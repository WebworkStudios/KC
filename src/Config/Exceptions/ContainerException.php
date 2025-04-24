<?php

declare(strict_types=1);

namespace Src\Config\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/**
 * Base exception for container errors
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
{
}