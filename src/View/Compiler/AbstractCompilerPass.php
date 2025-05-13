<?php


declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Basis-Klasse für Compiler-Passes
 *
 * Enthält gemeinsame Funktionalität für alle Compiler-Passes
 */
abstract class AbstractCompilerPass implements CompilerPassInterface
{
    /**
     * Priorität des Passes
     *
     * @var int
     */
    protected int $priority = 100;

    /**
     * Name des Passes
     *
     * @var string
     */
    protected string $name;

    /**
     * {@inheritDoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return $this->name ?? static::class;
    }

    /**
     * Wandelt Template-Variablennamen in PHP-Variablennamen um
     *
     * @param string $expression Ausdruck mit Template-Variablennamen
     * @return string Ausdruck mit PHP-Variablennamen
     */
    protected function transformVariables(string $expression): string
    {
        // Füge $ zu Variablennamen hinzu, die kein $ haben
        return preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)(?!\s*\(|\s*\$)\b/',
            function ($matches) {
                return '$' . $matches[1];
            },
            $expression
        );
    }

    /**
     * Wandelt einen Template-Ausdruck in einen sicheren PHP-Ausdruck um
     *
     * @param string $expression Template-Ausdruck
     * @param array<string> $allowedFunctions Erlaubte Funktionen
     * @return string Sicherer PHP-Ausdruck
     */
    protected function sanitizeExpression(string $expression, array $allowedFunctions = []): string
    {
        // Füge Standard-PHP-Funktionen hinzu
        $allowedFunctions = array_merge($allowedFunctions, [
            // Arrays
            'count', 'sizeof', 'array_key_exists', 'array_merge', 'array_filter',
            'array_map', 'array_reduce', 'array_values', 'array_keys',
            // Strings
            'trim', 'rtrim', 'ltrim', 'strlen', 'mb_strlen', 'strtolower',
            'strtoupper', 'ucfirst', 'ucwords', 'mb_strtolower', 'mb_strtoupper',
            'str_replace', 'str_contains', 'str_starts_with', 'str_ends_with',
            'substr', 'mb_substr', 'implode', 'explode',
            // Zahlen
            'number_format', 'round', 'ceil', 'floor', 'min', 'max', 'abs',
            // Prüfungen
            'isset', 'empty', 'is_array', 'is_string', 'is_numeric', 'is_null',
            'is_object', 'in_array', 'is_int', 'is_float', 'is_bool',
            // Zeit und Datum
            'date', 'time', 'strtotime',
            // Sonstiges
            'json_encode', 'json_decode', 'htmlspecialchars', 'htmlentities',
        ]);

        // Prüfe auf unerlaubte Funktionen
        preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $expression, $matches);

        foreach ($matches[1] as $function) {
            if (!in_array(strtolower($function), array_map('strtolower', $allowedFunctions), true)) {
                // Funktionsaufruf durch Safe-Wrapper ersetzen
                $expression = preg_replace(
                    '/\b' . preg_quote($function, '/') . '\s*\(/',
                    '$this->callFunction(\'' . $function . '\', [',
                    $expression
                );
                // Schließende Klammer ersetzen
                $expression = preg_replace('/\)(?![^(]*\()/', '])', $expression);
            }
        }

        return $expression;
    }
}