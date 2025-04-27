<?php

namespace Src\Security;

use RuntimeException;

/**
 * Exception, die bei CSRF-Token-Fehlern geworfen wird
 */
class CsrfTokenException extends RuntimeException
{
    // Erbt alle Funktionen von RuntimeException
}