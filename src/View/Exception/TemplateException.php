<?php

declare(strict_types=1);

namespace Src\View\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception für Template-Fehler
 *
 * Spezialisierte Exception-Klasse für Fehler im Templating-System
 */
class TemplateException extends RuntimeException
{
    /**
     * Erstellt eine neue TemplateException mit Kontextinformationen
     *
     * @param string $message Fehlermeldung
     * @param string|null $templateName Name des betroffenen Templates
     * @param int|null $lineNumber Zeilennummer des Fehlers (falls bekannt)
     * @param Throwable|null $previous Vorherige Exception
     * @return self
     */
    public static function withContext(
        string      $message,
        ?string     $templateName = null,
        ?int        $lineNumber = null,
        ?Throwable $previous = null
    ): self
    {
        $contextMessage = $message;

        if ($templateName !== null) {
            $contextMessage .= " in template '{$templateName}'";

            if ($lineNumber !== null) {
                $contextMessage .= " on line {$lineNumber}";
            }
        }

        return new self($contextMessage, 0, $previous);
    }

    /**
     * Erstellt eine neue TemplateException für einen nicht gefundenes Template
     *
     * @param string $templateName Name des Templates
     * @return self
     */
    public static function templateNotFound(string $templateName): self
    {
        return new self("Template '{$templateName}' not found");
    }

    /**
     * Erstellt eine neue TemplateException für eine fehlende Section
     *
     * @param string $sectionName Name der Section
     * @return self
     */
    public static function sectionNotStarted(string $sectionName): self
    {
        return new self("Cannot end section '{$sectionName}' because it was not started");
    }

    /**
     * Erstellt eine neue TemplateException für eine nicht registrierte Funktion
     *
     * @param string $functionName Name der Funktion
     * @return self
     */
    public static function functionNotRegistered(string $functionName): self
    {
        return new self("Function '{$functionName}' is not registered");
    }

    /**
     * Erstellt eine neue TemplateException für einen unerlaubten Funktionsaufruf
     *
     * @param string $functionName Name der Funktion
     * @return self
     */
    public static function functionNotAllowed(string $functionName): self
    {
        return new self("Function '{$functionName}' is not allowed in templates");
    }

    /**
     * Erstellt eine neue TemplateException für einen Kompilierungsfehler
     *
     * @param string $error Fehlerbeschreibung
     * @param string $templateName Name des Templates
     * @param Throwable|null $previous Vorherige Exception
     * @return self
     */
    public static function compilationError(string $error, string $templateName, ?Throwable $previous = null): self
    {
        return new self("Error compiling template '{$templateName}': {$error}", 0, $previous);
    }
}