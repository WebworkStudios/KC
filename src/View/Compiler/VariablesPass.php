<?php

declare(strict_types=1);

namespace Src\View\Compiler;

/**
 * Stark verbesserter VariablesPass
 *
 * Kritische Verbesserungen:
 * - Korrekte String-Literal-Behandlung
 * - Robuste Funktionsaufruf-Erkennung
 * - Bessere Punkt-Notation für Arrays/Objekte
 * - Schutz vor doppelter Transformation
 */
class VariablesPass extends AbstractCompilerPass
{
    protected int $priority = 80;
    protected string $name = 'Variables';

    public function process(string $code, array $context = []): string
    {
        // Escaped-Variablen ({{ var }})
        $code = preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/',
            function ($matches) {
                $expression = $this->processExpression(trim($matches[1]));
                return '<?php echo e(' . $expression . '); ?>';
            },
            $code
        );

        // Unescaped-Variablen ({!! var !!})
        $code = preg_replace_callback(
            '/\{!!\s*(.*?)\s*!!\}/',
            function ($matches) {
                $expression = $this->processExpression(trim($matches[1]));
                return '<?php echo ' . $expression . '; ?>';
            },
            $code
        );

        return $code;
    }

    /**
     * Verarbeitet einen kompletten Ausdruck
     */
    private function processExpression(string $expression): string
    {
        // Leere Ausdrücke abfangen
        if (empty($expression)) {
            return "''";
        }

        // String-Literale schützen
        $protectedStrings = [];
        $stringPlaceholder = '___STRING_PLACEHOLDER_';
        $stringCounter = 0;

        // Einfache und doppelte Anführungszeichen schützen
        $expression = preg_replace_callback(
            '/([\'"])((?:\\\\.|(?!\1).)*)\1/',
            function ($matches) use (&$protectedStrings, $stringPlaceholder, &$stringCounter) {
                $placeholder = $stringPlaceholder . $stringCounter . '___';
                $protectedStrings[$placeholder] = $matches[0];
                $stringCounter++;
                return $placeholder;
            },
            $expression
        );

        // Funktionsaufrufe verarbeiten (vor Variablen-Transformation)
        $expression = $this->processFunctionCalls($expression);

        // Variablen transformieren
        $expression = $this->transformVariables($expression);

        // Array/Objekt-Zugriff mit Punkt-Notation umwandeln
        $expression = $this->transformDotNotation($expression);

        // Ternäre Operatoren und andere PHP-Syntax beibehalten
        $expression = $this->fixOperators($expression);

        // String-Literale wiederherstellen
        foreach ($protectedStrings as $placeholder => $original) {
            $expression = str_replace($placeholder, $original, $expression);
        }

        return $expression;
    }

    /**
     * Verbesserte Funktionsaufruf-Verarbeitung
     */
    private function processFunctionCalls(string $expression): string
    {
        // Template-Funktionen wie url('route', params) korrekt behandeln
        $expression = preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\(([^)]*)\)/',
            function ($matches) {
                $functionName = $matches[1];
                $params = $matches[2];

                // Bekannte Template-Funktionen
                if (in_array($functionName, [
                    'url', 'route', 'asset', 'dateFormat', 'date', 'length',
                    'upper', 'lower', 'trim', 'truncate', 'json', 'e', 'raw'
                ])) {
                    // Template-Engine-Funktionsaufruf
                    if (!empty($params)) {
                        // Parameter verarbeiten
                        $processedParams = $this->processParameters($params);
                        return "\$this->callFunction('{$functionName}', [{$processedParams}])";
                    } else {
                        return "\$this->callFunction('{$functionName}', [])";
                    }
                }

                // Standard PHP-Funktionen unverändert lassen
                return $matches[0];
            },
            $expression
        );

        return $expression;
    }

    /**
     * Verarbeitet Funktionsparameter
     */
    private function processParameters(string $params): string
    {
        if (empty($params)) {
            return '';
        }

        // Einfache Implementierung: Parameter durch Komma trennen
        $paramArray = [];
        $parts = $this->splitParameters($params);

        foreach ($parts as $part) {
            $part = trim($part);

            // Array-Syntax erkennen ([key => value, ...])
            if (str_starts_with($part, '[') && str_ends_with($part, ']')) {
                $paramArray[] = $this->transformArraySyntax($part);
            }
            // String-Literal
            elseif (preg_match('/^[\'"].*[\'"]$/', $part)) {
                $paramArray[] = $part;
            }
            // Nummer
            elseif (is_numeric($part)) {
                $paramArray[] = $part;
            }
            // Variable
            elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $part)) {
                $paramArray[] = '$' . $part;
            }
            // Komplexerer Ausdruck
            else {
                $paramArray[] = $this->transformVariables($part);
            }
        }

        return implode(', ', $paramArray);
    }

    /**
     * Splittet Parameter unter Berücksichtigung von Klammern und Anführungszeichen
     */
    private function splitParameters(string $params): array
    {
        $result = [];
        $current = '';
        $depth = 0;
        $inString = false;
        $stringChar = null;
        $length = strlen($params);

        for ($i = 0; $i < $length; $i++) {
            $char = $params[$i];
            $prevChar = $i > 0 ? $params[$i - 1] : '';

            if (!$inString) {
                if ($char === '"' || $char === "'") {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $result[] = $current;
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
            $result[] = $current;
        }

        return $result;
    }

    /**
     * Transformiert Array-Syntax
     */
    private function transformArraySyntax(string $array): string
    {
        // [key => value, key2 => value2] zu ['key' => $value, 'key2' => $value2]
        return preg_replace_callback(
            '/([a-zA-Z_][a-zA-Z0-9_]*)\s*=>\s*([a-zA-Z_][a-zA-Z0-9_]*)/',
            function ($matches) {
                return "'{$matches[1]}' => \${$matches[2]}";
            },
            $array
        );
    }

    /**
     * Verbesserte Variablen-Transformation
     */
    protected function transformVariables(string $expression): string
    {
        // Bereits transformierte Variablen nicht nochmal transformieren
        if (strpos($expression, '$') !== false) {
            return $expression;
        }

        // Variablennamen finden und mit $ präfixieren
        $expression = preg_replace_callback(
            '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/',
            function ($matches) {
                $word = $matches[1];

                // PHP-Keywords und -Konstanten nicht transformieren
                if (in_array($word, [
                    'true', 'false', 'null', 'TRUE', 'FALSE', 'NULL',
                    'and', 'or', 'xor', 'not', 'instanceof', 'new',
                    'class', 'function', 'return', 'if', 'else', 'elseif',
                    'foreach', 'for', 'while', 'do', 'switch', 'case',
                    'default', 'break', 'continue', 'public', 'private',
                    'protected', 'static', 'final', 'abstract', 'interface',
                    'implements', 'extends', 'namespace', 'use', 'try',
                    'catch', 'finally', 'throw', 'isset', 'empty', 'unset'
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

        return $expression;
    }

    /**
     * Transformiert Punkt-Notation zu Array/Objekt-Zugriff
     */
    private function transformDotNotation(string $expression): string
    {
        // $user.name zu $user['name'] oder $user->name
        return preg_replace_callback(
            '/\$([a-zA-Z_][a-zA-Z0-9_]*(?:\[.*?\])*)\.([a-zA-Z_][a-zA-Z0-9_]*(?:\[.*?\])*)/',
            function ($matches) {
                $base = '$' . $matches[1];
                $property = $matches[2];

                // Für Arrays: $user['property']
                return "{$base}['{$property}']";

                // Alternative für Objekte: $user->property (kann je nach Kontext gewählt werden)
                // return "{$base}->{$property}";
            },
            $expression
        );
    }

    /**
     * Korrigiert Operatoren
     */
    private function fixOperators(string $expression): string
    {
        // Template-spezifische Operatoren zu PHP-Operatoren
        $operators = [
            ' eq ' => ' == ',
            ' ne ' => ' != ',
            ' gt ' => ' > ',
            ' ge ' => ' >= ',
            ' lt ' => ' < ',
            ' le ' => ' <= ',
            ' and ' => ' && ',
            ' or ' => ' || ',
            ' not ' => ' !',
        ];

        foreach ($operators as $templateOp => $phpOp) {
            $expression = str_replace($templateOp, $phpOp, $expression);
        }

        return $expression;
    }

    /**
     * Verbesserte Expression-Sanitization
     */
    protected function sanitizeExpression(string $expression, array $allowedFunctions = []): string
    {
        // Standard erlaubte Funktionen
        $defaultAllowed = [
            // Arrays
            'count', 'sizeof', 'array_key_exists', 'array_merge', 'array_filter',
            'array_map', 'array_reduce', 'array_values', 'array_keys', 'in_array',
            // Strings
            'trim', 'rtrim', 'ltrim', 'strlen', 'mb_strlen', 'strtolower',
            'strtoupper', 'ucfirst', 'ucwords', 'mb_strtolower', 'mb_strtoupper',
            'str_replace', 'str_contains', 'str_starts_with', 'str_ends_with',
            'substr', 'mb_substr', 'implode', 'explode', 'str_pad',
            // Zahlen
            'number_format', 'round', 'ceil', 'floor', 'min', 'max', 'abs',
            'is_numeric', 'is_int', 'is_float',
            // Prüfungen
            'isset', 'empty', 'is_array', 'is_string', 'is_numeric', 'is_null',
            'is_object', 'is_bool', 'is_callable',
            // Zeit und Datum
            'date', 'time', 'strtotime', 'mktime',
            // Sonstiges
            'json_encode', 'json_decode', 'htmlspecialchars', 'htmlentities',
            'urlencode', 'urldecode', 'base64_encode', 'base64_decode',
            // Template-spezifische Funktionen
            'e', 'raw', 'upper', 'lower', 'length', 'truncate', 'dateFormat'
        ];

        $allowedFunctions = array_merge($defaultAllowed, $allowedFunctions);

        // Gefährliche Funktionen blockieren
        $dangerousFunctions = [
            'eval', 'exec', 'system', 'shell_exec', 'passthru', 'file_get_contents',
            'file_put_contents', 'fopen', 'fwrite', 'unlink', 'rmdir', 'mkdir',
            'chmod', 'chown', 'include', 'require', 'include_once', 'require_once',
            'phpinfo', 'mail', 'header', 'setcookie', 'session_start', 'exit', 'die'
        ];

        // Prüfe auf gefährliche Funktionen
        foreach ($dangerousFunctions as $func) {
            if (preg_match('/\b' . preg_quote($func) . '\s*\(/', $expression)) {
                throw new \InvalidArgumentException("Dangerous function '{$func}' not allowed in templates");
            }
        }

        return $expression;
    }
}