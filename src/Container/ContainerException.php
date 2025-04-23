<?php

declare(strict_types=1);

namespace Src\Core\Container;

use Src\Core\Container\ContainerExceptionInterface;

/**
 * Exception, die bei einem allgemeinen Fehler im Container geworfen wird
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
{
}