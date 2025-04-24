<?php

declare(strict_types=1);

namespace Src\Config\Exceptions;

/**
 * Exception for when a circular dependency is detected
 */
class CircularDependencyException extends ContainerException
{
}