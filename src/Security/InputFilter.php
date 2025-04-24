<?php
declare(strict_types=1);
namespace Src\Security;

class InputFilter
{
    /**
     * Sanitize a value using a filter
     *
     * @param mixed $value
     * @param int $filter
     * @return mixed
     */
    public function sanitize(mixed $value, int $filter = FILTER_SANITIZE_SPECIAL_CHARS): mixed
    {
        if (is_array($value)) {
            return array_map(fn($item) => $this->sanitize($item, $filter), $value);
        }

        return filter_var($value, $filter);
    }

    /**
     * Sanitize for XSS protection
     *
     * @param mixed $value
     * @return string
     */
    public function xssSanitize(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn($item) => $this->xssSanitize($item), $value));
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
     *
     * @param string $email
     * @return bool
     */
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate a URL
     *
     * @param string $url
     * @return bool
     */
    public function validateUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate an integer
     *
     * @param mixed $value
     * @return bool
     */
    public function validateInt(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate a float
     *
     * @param mixed $value
     * @return bool
     */
    public function validateFloat(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Sanitize filename
     *
     * @param string $filename
     * @return string
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove any path info from the filename
        $filename = basename($filename);

        // Remove potentially dangerous characters
        $filename = preg_replace('/[^\w\.-]/i', '_', $filename);

        return $filename;
    }
}