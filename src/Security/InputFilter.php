<?php
declare(strict_types=1);
namespace Src\Security;

class InputFilter
{
    /**
     * Sanitize a value using a filter
     *
     * @param mixed $value Value to sanitize
     * @param int $filter Filter constant to apply
     * @param mixed $options Additional filter options
     * @return mixed Sanitized value
     */
    public static function sanitize(mixed $value, int $filter = FILTER_SANITIZE_SPECIAL_CHARS, mixed $options = null): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => self::sanitize($item, $filter, $options), $value);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $value = (string)$value;
        }

        // For deprecated FILTER_SANITIZE_SPECIAL_CHARS in PHP 8.1+, use htmlspecialchars directly
        if ($filter === FILTER_SANITIZE_SPECIAL_CHARS && is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if (is_scalar($value) || is_null($value)) {
            return filter_var($value, $filter, $options);
        }

        // Non-scalar values without __toString cannot be filtered
        return null;
    }

    /**
     * Sanitize for XSS protection with improved pattern matching
     *
     * @param mixed $value Value to sanitize
     * @return string Sanitized string
     */
    public static function xssSanitize(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => self::xssSanitize($item), $value));
        }

        if (!is_string($value)) {
            $value = (string)$value;
        }

        // Convert special characters to HTML entities
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Improved event handler detection (handles various quote styles and spacing)
        $value = preg_replace('/\bon\w+\s*=\s*(["\'])?.*?\1/i', '', $value);

        // Improved JavaScript URL detection (handles various quote styles and spacing)
        $value = preg_replace('/href\s*=\s*(["\'])?javascript\s*:/i', 'href=$1#', $value);

        return $value;
    }

    /**
     * Validate an email address with optional DNS check
     *
     * @param string $email Email to validate
     * @param bool $checkDns Whether to check domain DNS records
     * @return bool Whether email is valid
     */
    public static function validateEmail(string $email, bool $checkDns = false): bool
    {
        $isValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        if ($isValid && $checkDns) {
            $domain = substr(strrchr($email, "@"), 1);
            return $domain && checkdnsrr($domain, 'MX');
        }

        return $isValid;
    }

    /**
     * Validate a URL with optional scheme requirement
     *
     * @param string $url URL to validate
     * @param bool $requireHttps Whether to require HTTPS scheme
     * @return bool Whether URL is valid
     */
    public static function validateUrl(string $url, bool $requireHttps = false): bool
    {
        $isValid = filter_var($url, FILTER_VALIDATE_URL) !== false;

        if ($isValid && $requireHttps) {
            return str_starts_with(strtolower($url), 'https://');
        }

        return $isValid;
    }

    /**
     * Validate an integer with optional range check
     *
     * @param mixed $value Value to validate
     * @param int|null $min Minimum value (optional)
     * @param int|null $max Maximum value (optional)
     * @return bool Whether value is a valid integer within range
     */
    public static function validateInt(mixed $value, ?int $min = null, ?int $max = null): bool
    {
        $options = [];

        if ($min !== null) {
            $options['min_range'] = $min;
        }

        if ($max !== null) {
            $options['max_range'] = $max;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => $options]) !== false;
    }

    /**
     * Validate a float with optional range check
     *
     * @param mixed $value Value to validate
     * @param float|null $min Minimum value (optional)
     * @param float|null $max Maximum value (optional)
     * @return bool Whether value is a valid float within range
     */
    public static function validateFloat(mixed $value, ?float $min = null, ?float $max = null): bool
    {
        $isValid = filter_var($value, FILTER_VALIDATE_FLOAT) !== false;

        if (!$isValid) {
            return false;
        }

        $floatValue = (float)$value;

        if ($min !== null && $floatValue < $min) {
            return false;
        }

        if ($max !== null && $floatValue > $max) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize filename with improved security measures
     *
     * @param string $filename Filename to sanitize
     * @param int $maxLength Maximum filename length
     * @return string Sanitized filename
     */
    public static function sanitizeFilename(string $filename, int $maxLength = 255): string
    {
        // Remove path information
        $filename = basename($filename);

        // Remove potentially dangerous characters
        $filename = preg_replace('/[^\w\.-]/i', '_', $filename);

        // Remove leading dots (hidden files)
        $filename = ltrim($filename, '.');

        // Check for empty filename and set default
        if (empty($filename) || $filename === '_') {
            $filename = 'file';
        }

        // Limit maximum length
        if (strlen($filename) > $maxLength) {
            $extension = '';
            if (str_contains($filename, '.')) {
                $extension = strrchr($filename, '.');
                $filename = substr($filename, 0, -strlen($extension));
            }
            $filename = substr($filename, 0, $maxLength - strlen($extension)) . $extension;
        }

        return $filename;
    }

    /**
     * Validate string length
     *
     * @param string $value String to validate
     * @param int $min Minimum length
     * @param int $max Maximum length
     * @return bool Whether string length is valid
     */
    public static function validateLength(string $value, int $min = 0, int $max = PHP_INT_MAX): bool
    {
        $length = mb_strlen($value, 'UTF-8');
        return $length >= $min && $length <= $max;
    }

    /**
     * Validate string against a regular expression pattern
     *
     * @param string $value String to validate
     * @param string $pattern Regular expression pattern
     * @return bool Whether string matches pattern
     */
    public static function validatePattern(string $value, string $pattern): bool
    {
        return (bool)preg_match($pattern, $value);
    }

    /**
     * Validate alphanumeric string with optional allowed characters
     *
     * @param string $value String to validate
     * @param string $allowedChars Additional allowed characters
     * @return bool Whether string is valid
     */
    public static function validateAlphaNumeric(string $value, string $allowedChars = ''): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9' . preg_quote($allowedChars, '/') . ']+$/', $value);
    }

    /**
     * Validate date string according to a format
     *
     * @param string $value Date string to validate
     * @param string $format Date format (same as DateTime format)
     * @return bool Whether date string is valid
     */
    public static function validateDate(string $value, string $format = 'Y-m-d'): bool
    {
        $date = \DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }

    /**
     * Validate value against a list of allowed values
     *
     * @param mixed $value Value to validate
     * @param array $allowedValues List of allowed values
     * @param bool $strict Whether to use strict comparison
     * @return bool Whether value is in allowed list
     */
    public static function validateInList(mixed $value, array $allowedValues, bool $strict = true): bool
    {
        return in_array($value, $allowedValues, $strict);
    }

    /**
     * Validate and throw exception if invalid
     *
     * @param mixed $value Value to validate
     * @param string $method Validation method name
     * @param string $message Error message if validation fails
     * @param array $args Additional arguments for validation method
     * @throws \InvalidArgumentException If validation fails
     */
    public static function validateOrThrow(mixed $value, string $method, string $message, array $args = []): void
    {
        if (!is_callable([self::class, $method])) {
            throw new \InvalidArgumentException("Invalid validation method: $method");
        }

        if (!call_user_func_array([self::class, $method], array_merge([$value], $args))) {
            throw new \InvalidArgumentException($message);
        }
    }
}