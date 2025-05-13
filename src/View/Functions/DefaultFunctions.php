<?php

declare(strict_types=1);

namespace Src\View\Functions;

use Src\Http\Router;
use Src\View\FunctionProviderInterface;

/**
 * Standard-Hilfsfunktionen für Templates
 */
class DefaultFunctions implements FunctionProviderInterface
{
    /**
     * Router für URL-Generierung
     *
     * @var Router|null
     */
    private ?Router $router;

    /**
     * Erstellt eine neue DefaultFunctions-Instanz
     *
     * @param Router|null $router Router für URL-Generierung
     */
    public function __construct(?Router $router = null)
    {
        $this->router = $router;
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctions(): array
    {
        return [
            // URL-Generierung
            'url' => [$this, 'url'],
            'asset' => [$this, 'asset'],
            'route' => [$this, 'route'],

            // HTML-Hilfen
            'class' => [$this, 'class'],
            'attr' => [$this, 'attr'],
            'checked' => [$this, 'checked'],
            'selected' => [$this, 'selected'],

            // Formatierung
            'dateFormat' => [$this, 'dateFormat'],
            'numberFormat' => [$this, 'numberFormat'],
            'currencyFormat' => [$this, 'currencyFormat'],

            // Sammlungen
            'collect' => [$this, 'collect'],
            'pluck' => [$this, 'pluck'],
            'only' => [$this, 'only'],
            'except' => [$this, 'except'],

            // Strings
            'plural' => [$this, 'plural'],
            'slug' => [$this, 'slug'],
            'excerpt' => [$this, 'excerpt'],

            // Sonstiges
            'dump' => [$this, 'dump'],
            'when' => [$this, 'when'],
            'unless' => [$this, 'unless'],
        ];
    }

    /**
     * Generiert eine URL für eine Route
     *
     * @param string $name Routenname
     * @param array<string, mixed> $parameters Routenparameter
     * @return string URL
     */
    public function url(string $name, array $parameters = []): string
    {
        if ($this->router === null) {
            return '#'; // Fallback, wenn kein Router verfügbar
        }

        try {
            return $this->router->url($name, $parameters);
        } catch (\Throwable $e) {
            return '#'; // Fallback bei Fehler
        }
    }

    /**
     * Generiert eine URL für ein Asset
     *
     * @param string $path Asset-Pfad
     * @return string Asset-URL
     */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');

        // Basis-URL ermitteln
        $baseUrl = isset($_SERVER['HTTP_HOST'])
            ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
            : '';

        return $baseUrl . '/assets/' . $path;
    }

    /**
     * Generiert eine URL für eine Route (Alias für url())
     *
     * @param string $name Routenname
     * @param array<string, mixed> $parameters Routenparameter
     * @return string URL
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->url($name, $parameters);
    }

    /**
     * Generiert ein HTML-Klassenattribut
     *
     * @param array<string, bool> $classes Klassen und Bedingungen
     * @return string Klassen-String
     */
    public function class(array $classes): string
    {
        $activeClasses = [];

        foreach ($classes as $class => $condition) {
            if ($condition) {
                $activeClasses[] = $class;
            }
        }

        return implode(' ', $activeClasses);
    }

    /**
     * Generiert HTML-Attribute
     *
     * @param array<string, mixed> $attributes Attribute und Werte
     * @return string Attribute-String
     */
    public function attr(array $attributes): string
    {
        $html = [];

        foreach ($attributes as $key => $value) {
            // Boolean-Attribute (required, disabled, etc.)
            if (is_bool($value)) {
                if ($value) {
                    $html[] = $key;
                }
                continue;
            }

            // Normale Attribute
            $html[] = $key . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return implode(' ', $html);
    }

    /**
     * Generiert ein checked-Attribut für Checkboxen und Radiobuttons
     *
     * @param mixed $value Aktueller Wert
     * @param mixed $check Wert zum Vergleich
     * @return string checked-Attribut oder leerer String
     */
    public function checked(mixed $value, mixed $check): string
    {
        return $value == $check ? 'checked' : '';
    }

    /**
     * Generiert ein selected-Attribut für Selectboxen
     *
     * @param mixed $value Aktueller Wert
     * @param mixed $selected Wert zum Vergleich
     * @return string selected-Attribut oder leerer String
     */
    public function selected(mixed $value, mixed $selected): string
    {
        return $value == $selected ? 'selected' : '';
    }

    /**
     * Formatiert ein Datum
     *
     * @param mixed $date Datum (string, timestamp, DateTime)
     * @param string $format Datumsformat
     * @return string Formatiertes Datum
     */
    public function dateFormat(mixed $date, string $format = 'd.m.Y'): string
    {
        if ($date === null) {
            return '';
        }

        if ($date instanceof \DateTimeInterface) {
            return $date->format($format);
        }

        if (is_numeric($date)) {
            return date($format, (int)$date);
        }

        if (is_string($date)) {
            $timestamp = strtotime($date);
            return $timestamp !== false ? date($format, $timestamp) : '';
        }

        return '';
    }

    /**
     * Formatiert eine Zahl
     *
     * @param mixed $number Zahl
     * @param int $decimals Nachkommastellen
     * @param string $decPoint Dezimalpunkt
     * @param string $thousandsSep Tausendertrennzeichen
     * @return string Formatierte Zahl
     */
    public function numberFormat(mixed $number, int $decimals = 2, string $decPoint = ',', string $thousandsSep = '.'): string
    {
        return number_format((float)$number, $decimals, $decPoint, $thousandsSep);
    }

    /**
     * Formatiert einen Betrag als Währung
     *
     * @param mixed $amount Betrag
     * @param string $currency Währung
     * @param string $locale Gebietsschema
     * @return string Formatierter Betrag
     */
    public function currencyFormat(mixed $amount, string $currency = 'EUR', string $locale = 'de_DE'): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        return $formatter->formatCurrency((float)$amount, $currency);
    }

    /**
     * Erstellt eine Collection aus einem Array
     *
     * @param array<mixed> $items Elemente
     * @return array<mixed> Collection
     */
    public function collect(array $items): array
    {
        return $items;
    }

    /**
     * Extrahiert einen Wert aus jedem Element einer Collection
     *
     * @param array<mixed> $items Collection
     * @param string $key Schlüssel
     * @return array<mixed> Extrahierte Werte
     */
    public function pluck(array $items, string $key): array
    {
        $result = [];

        foreach ($items as $item) {
            if (is_object($item) && isset($item->$key)) {
                $result[] = $item->$key;
            } elseif (is_array($item) && isset($item[$key])) {
                $result[] = $item[$key];
            }
        }

        return $result;
    }

    /**
     * Filtert ein Array auf bestimmte Schlüssel
     *
     * @param array<string, mixed> $array Array
     * @param array<string> $keys Zu behaltende Schlüssel
     * @return array<string, mixed> Gefiltertes Array
     */
    public function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Filtert bestimmte Schlüssel aus einem Array
     *
     * @param array<string, mixed> $array Array
     * @param array<string> $keys Zu entfernende Schlüssel
     * @return array<string, mixed> Gefiltertes Array
     */
    public function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Gibt die korrekte Pluralform zurück
     *
     * @param int $count Anzahl
     * @param string $singular Singular-Form
     * @param string $plural Plural-Form
     * @return string Korrekte Form
     */
    public function plural(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }

    /**
     * Erstellt einen URL-freundlichen Slug
     *
     * @param string $string String
     * @return string Slug
     */
    public function slug(string $string): string
    {
        // Umlaute und Sonderzeichen ersetzen
        $replacements = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'
        ];

        $string = strtr($string, $replacements);

        // Nicht-alphanumerische Zeichen durch Bindestriche ersetzen
        $string = preg_replace('/[^a-zA-Z0-9]/', '-', $string);

        // Mehrere Bindestriche durch einen ersetzen
        $string = preg_replace('/-+/', '-', $string);

        // Bindestriche am Anfang und Ende entfernen
        return trim($string, '-');
    }

    /**
     * Erstellt einen Textauszug
     *
     * @param string $text Text
     * @param int $length Maximale Länge
     * @param string $end Ende-Zeichen
     * @return string Auszug
     */
    public function excerpt(string $text, int $length = 100, string $end = '...'): string
    {
        // HTML-Tags entfernen
        $text = strip_tags($text);

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // Text kürzen
        $text = mb_substr($text, 0, $length);

        // Letztes Wort nicht abschneiden
        $text = mb_substr($text, 0, mb_strrpos($text, ' '));

        return $text . $end;
    }

    /**
     * Gibt Debug-Informationen aus
     *
     * @param mixed $var Variable
     * @return string HTML-formatierte Debug-Informationen
     */
    public function dump(mixed $var): string
    {
        ob_start();
        var_dump($var);
        $output = ob_get_clean();

        return '<pre>' . htmlspecialchars($output, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    /**
     * Führt eine Aktion aus, wenn eine Bedingung erfüllt ist
     *
     * @param bool $condition Bedingung
     * @param mixed $value Wert, wenn die Bedingung erfüllt ist
     * @param mixed $default Wert, wenn die Bedingung nicht erfüllt ist
     * @return mixed Ergebnis
     */
    public function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        return $condition ? $value : $default;
    }

    /**
     * Führt eine Aktion aus, wenn eine Bedingung nicht erfüllt ist
     *
     * @param bool $condition Bedingung
     * @param mixed $value Wert, wenn die Bedingung nicht erfüllt ist
     * @param mixed $default Wert, wenn die Bedingung erfüllt ist
     * @return mixed Ergebnis
     */
    public function unless(bool $condition, mixed $value, mixed $default = null): mixed
    {
        return !$condition ? $value : $default;
    }
}