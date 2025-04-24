<?php


namespace Src\Security;

class InputFilter
{
    /**
     * Sanitize a value using a filter
     */
    public static function sanitize($value, int $filter = FILTER_SANITIZE_SPECIAL_CHARS)
    {
        if (is_array($value)) {
            return array_map(fn($item) => self::sanitize($item, $filter), $value);
        }

        return filter_var($value, $filter);
    }

    /**
     * Sanitize for XSS protection
     */
    public static function xssSanitize($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => self::xssSanitize($item), $value));
        }

        if (!is_string($value)) {
            $value = (string)$value;
        }

        // Convert special characters to HTML entities
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove JavaScript event handlers
        $value = preg_replace('/on\w+="[^"]*"/i', '', $value);

        // Remove JavaScript in href attributes
        $value = preg_replace('/href="javascript:[^"]*"/i', 'href="#"', $value);

        return $value;
    }

    /**
     * Validate an email address
     */
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate a URL
     */
    public static function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate an integer
     */
    public static function validateInt($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate a float
     */
    public static function validateFloat($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Sanitize filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Remove any path info from the filename
        $filename = basename($filename);

        // Remove potentially dangerous characters
        $filename = preg_replace('/[^\w\.-]/i', '_', $filename);

        return $filename;
    }
}