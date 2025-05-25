<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Stark verbesserter PipePass für Pipe-Operationen
 *
 * Kritische Verbesserungen:
 * - Korrekte Pipe-Chain-Verarbeitung
 * - Robuste Parameter-Behandlung
 * - Bessere Fehlerbehandlung
 * - Unterstützung für komplexe Ausdrücke
 */
class PipePass extends AbstractCompilerPass
{
    protected int $priority = 70;
    protected string $name = 'Pipes';

    /** @var array<string, string> Mapping von Template-Filtern zu PHP-Funktionen */
    private array $filterMappings = [
        'length' => 'count',
        'size' => 'count',
        'upper' => 'strtoupper',
        'lower' => 'strtolower',
        'trim' => 'trim',
        'escape' => 'htmlspecialchars',
        'e' => 'htmlspecialchars',
        'raw' => 'strval',
        'json' => 'json_encode',
        'url_encode' => 'urlencode',
        'base64' => 'base64_encode'
    ];

    public function process(string $code, array $context = []): string
    {
        // Pipes in verschiedenen Kontexten verarbeiten

        // 1. Pipes in If-Statements
        $code = preg_replace_callback(
            '/{%\s*if\s+(.*?\|.*?)\s*%}/',
            function ($matches) {
                $condition = $this->processPipeExpression($matches[1]);
                return '{% if ' . $condition . ' %}';
            },
            $code
        );

        // 2. Pipes in ElseIf-Statements
        $code = preg_replace_callback(
            '/{%\s*elseif\s+(.*?\|.*?)\s*%}/',
            function ($matches) {
                $condition = $this->processPipeExpression($matches[1]);
                return '{% elseif ' . $condition . ' %}';
            },
            $code
        );

        // 3. Pipes in Variablen-Ausgaben ({{ var|filter }})
        $code = preg_replace_callback(
            '/\{\{\s*(.*?\|.*?)\s*\}\}/',
            function ($matches) {
                $expression = $this->processPipeExpression($matches[1]);
                return '{{ ' . $expression . ' }}';
            },
            $code
        );

        // 4. Pipes in unescaped Variablen ({!! var|filter !!})
        $code = preg_replace_callback(
            '/\{!!\s*(.*?\|.*?)\s*!!\}/',
            function ($matches) {
                $expression = $this->processPipeExpression($matches[1]);
                return '{!! ' . $expression . ' !!}';
            },
            $code
        );

        // 5. Pipes in Foreach-Statements
        $code = preg_replace_callback(
            '/{%\s*foreach\s+(.*?\|.*?)\s+as\s+(.*?)\s*%}/',
            function ($matches) {
                $collection = $this->processPipeExpression($matches[1]);
                $iterator = $matches[2];
                return '{% foreach ' . $collection . ' as ' . $iterator . ' %}';
            },
            $code
        );

        return $code;
    }

    /**
     * Verarbeitet einen vollständigen Pipe-Ausdruck
     */
    private function processPipeExpression(string $expression): string
    {
        // Trimmen und leere Ausdrücke abfangen
        $expression = trim($expression);
        if (empty($expression)) {
            return "''";
        }

        // Keine Pipes vorhanden
        if (!str_contains($expression, '|')) {
            return $expression;
        }

        // String-Literale vor Verarbeitung schützen
        $protectedStrings = [];
        $expression = $this->protectStringLiterals($expression, $protectedStrings);

        // Pipe-Teile aufteilen
        $parts = $this->splitPipeExpression($expression);

        if (count($parts) < 2) {
            // Keine gültigen Pipes gefunden
            return $this->restoreStringLiterals($expression, $protectedStrings);
        }

        // Basis-Ausdruck (erstes Element)
        $baseExpression = trim(array_shift($parts));
        $result = $this->processBaseExpression($baseExpression);

        // Pipe-Filter anwenden
        foreach ($parts as $filterPart) {
            $result = $this->applyFilter($result, trim($filterPart));
        }

        // String-Literale wiederherstellen
        return $this->restoreStringLiterals($result, $protectedStrings);
    }

    /**
     * Schützt String-Literale vor Pipe-Verarbeitung
     */
    private function protectStringLiterals(string $expression, array &$protectedStrings): string
    {
        $placeholder = '___STR_';
        $counter = 0;

        return preg_replace_callback(
            '/([\'"])((?:\\\\.|(?!\1).)*)\1/',
            function ($matches) use (&$protectedStrings, $placeholder, &$counter) {
                $key = $placeholder . $counter . '___';
                $protectedStrings[$key] = $matches[0];
                $counter++;
                return $key;
            },
            $expression
        );
    }

    /**
     * Stellt String-Literale wieder her
     */
    private function restoreStringLiterals(string $expression, array $protectedStrings): string
    {
        foreach ($protectedStrings as $placeholder => $original) {
            $expression = str_replace($placeholder, $original, $expression);
        }
        return $expression;
    }

    /**
     * Splittet Pipe-Ausdruck unter Berücksichtigung von Klammern und Anführungszeichen
     */
    private function splitPipeExpression(string $expression): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            $prevChar = $i > 0 ? $expression[$i - 1] : '';

            if (!$inString) {
                if (str_contains('___STR_', substr($expression, $i, 7))) {
                    // Geschützter String gefunden
                    $endPos = strpos($expression, '___', $i + 7);
                    if ($endPos !== false) {
                        $current .= substr($expression, $i, $endPos - $i + 3);
                        $i = $endPos + 2;
                        continue;
                    }
                } elseif ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === '|' && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if (!empty($current)) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Verarbeitet den Basis-Ausdruck (vor dem ersten Pipe)
     */
    private function processBaseExpression(string $expression): string
    {
        // Einfache Variable
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expression)) {
            return '$' . $expression;
        }

        // Komplexerer Ausdruck - könnte bereits $ enthalten
        if (!str_contains($expression, '$')) {
            return $this->transformVariables($expression);
        }

        return $expression;
    }

    /**
     * Wendet einen Filter auf einen Ausdruck an
     */
    private function applyFilter(string $expression, string $filter): string
    {
        // Filter und Parameter trennen
        $filterName = $filter;
        $arguments = [];

        if (str_contains($filter, ':')) {
            $parts = explode(':', $filter, 2);
            $filterName = trim($parts[0]);
            $argumentsString = trim($parts[1]);

            if (!empty($argumentsString)) {
                $arguments = $this->parseFilterArguments($argumentsString);
            }
        }

        return $this->buildFilterCall($expression, $filterName, $arguments);
    }

    /**
     * Parst Filter-Argumente
     */
    private function parseFilterArguments(string $argumentsString): array
    {
        $arguments = [];
        $parts = $this->splitArguments($argumentsString);

        foreach ($parts as $part) {
            $part = trim($part);

            if (empty($part)) {
                continue;
            }

            // String-Literal
            if (preg_match('/^[\'"].*[\'"]$/', $part)) {
                $arguments[] = $part;
            }
            // Numerisch
            elseif (is_numeric($part)) {
                $arguments[] = $part;
            }
            // Boolean
            elseif (in_array(strtolower($part), ['true', 'false'])) {
                $arguments[] = strtolower($part);
            }
            // Variable
            elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part)) {
                $arguments[] = '$' . $part;
            }
            // Komplexer Ausdruck
            else {
                $arguments[] = $this->transformVariables($part);
            }
        }

        return $arguments;
    }

    /**
     * Splittet Argumente unter Berücksichtigung von Klammern
     */
    private function splitArguments(string $argumentsString): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($argumentsString);

        for ($i = 0; $i < $length; $i++) {
            $char = $argumentsString[$i];
            $prevChar = $i > 0 ? $argumentsString[$i - 1] : '';

            if (!$inString) {
                if ($char === '"' || $char === "'") {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $arguments[] = $current;
                    $current = '';
                    continue;
                }
            } else {
                if ($char === $stringChar && $prevChar !== '\\') {
                    $inString = false;
                    $stringChar = null;
                }
            }

            $current .= $char;
        }

        if (!empty($current)) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * Erstellt den Funktionsaufruf für einen Filter
     */
    private function buildFilterCall(string $expression, string $filterName, array $arguments): string
    {
        // Spezielle Filter behandeln
        switch ($filterName) {
            case 'length':
            case 'size':
                return $this->buildLengthFilter($expression);

            case 'default':
                $default = $arguments[0] ?? "''";
                return "(!empty({$expression}) ? {$expression} : {$default})";

            case 'escape':
            case 'e':
                return "htmlspecialchars((string){$expression}, ENT_QUOTES | ENT_HTML5, 'UTF-8', false)";

            case 'raw':
                return "(string){$expression}";

            case 'upper':
                return "mb_strtoupper((string){$expression}, 'UTF-8')";

            case 'lower':
                return "mb_strtolower((string){$expression}, 'UTF-8')";

            case 'trim':
                $chars = $arguments[0] ?? "' \\t\\n\\r\\0\\x0B'";
                return "trim((string){$expression}, {$chars})";

            case 'truncate':
                $length = $arguments[0] ?? '100';
                $suffix = $arguments[1] ?? "'...'";
                return "\$this->callFunction('truncate', [{$expression}, {$length}, {$suffix}])";

            case 'date':
            case 'dateFormat':
                $format = $arguments[0] ?? "'d.m.Y'";
                return "\$this->callFunction('date', [{$expression}, {$format}])";

            case 'json':
                $options = $arguments[0] ?? '0';
                return "json_encode({$expression}, {$options} | JSON_UNESCAPED_UNICODE)";

            case 'url_encode':
                return "urlencode((string){$expression})";

            case 'base64':
                return "base64_encode((string){$expression})";

            case 'nl2br':
                return "nl2br(htmlspecialchars((string){$expression}, ENT_QUOTES, 'UTF-8'))";

            case 'replace':
                $search = $arguments[0] ?? "''";
                $replace = $arguments[1] ?? "''";
                return "str_replace({$search}, {$replace}, (string){$expression})";

            default:
                // Template-Engine-Funktion verwenden
                $allArgs = array_merge([$expression], $arguments);
                $argsString = implode(', ', $allArgs);
                return "\$this->callFunction('{$filterName}', [{$argsString}])";
        }
    }

    /**
     * Erstellt einen speziellen Length-Filter
     */
    private function buildLengthFilter(string $expression): string
    {
        return "(is_array({$expression}) || {$expression} instanceof \\Countable ? count({$expression}) : " .
            "(is_string({$expression}) ? mb_strlen({$expression}, 'UTF-8') : " .
            "({$expression} === null ? 0 : 1)))";
    }

    /**
     * Transformiert Variablen (vereinfachte Version)
     */
    protected function transformVariables(string $expression): string
    {
        // Bereits mit $ präfixierte Variablen nicht nochmal transformieren
        if (str_contains($expression, '$')) {
            return $expression;
        }

        return preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/',
            function ($matches) {
                $word = $matches[1];

                // PHP-Keywords nicht transformieren
                if (in_array($word, [
                    'true', 'false', 'null', 'TRUE', 'FALSE', 'NULL',
                    'and', 'or', 'not', 'instanceof'
                ])) {
                    return $word;
                }

                // Funktionsnamen nicht transformieren
                if (function_exists($word)) {
                    return $word;
                }

                return '$' . $word;
            },
            $expression
        );
    }
}