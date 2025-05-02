<?php


namespace Src\View\Functions;

use Src\Http\Router;
use Src\View\FunctionProviderInterface;

/**
 * Standard-Hilfsfunktionen für die Template-Engine
 */
class DefaultFunctions implements FunctionProviderInterface
{
    /**
     * @var Router|null Router-Instanz für URL-Generierung
     */
    private ?Router $router;

    /**
     * Erstellt eine neue DefaultFunctions-Instanz
     *
     * @param Router|null $router Router-Instanz für URL-Generierung
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
            'e' => [$this, 'escape'],
            'escape' => [$this, 'escape'],
            'url' => [$this, 'url'],
            'asset' => [$this, 'asset'],
            'dateFormat' => [$this, 'dateFormat'],
            'numberFormat' => [$this, 'numberFormat'],
            'nl2br' => [$this, 'nl2br'],
            'truncate' => [$this, 'truncate'],
            'lower' => [$this, 'lower'],
            'upper' => [$this, 'upper'],
            'capitalize' => [$this, 'capitalize'],
            'plural' => [$this, 'plural'],
            'dump' => [$this, 'dump'],
            'slice' => [$this, 'slice'],
            'json' => [$this, 'json'],
            'when' => [$this, 'when'],
            'unless' => [$this, 'unless'],
            'class' => [$this, 'class'],
        ];
    }

    /**
     * Escapt HTML-Entities
     *
     * @param mixed $value Zu escapender Wert
     * @return string Escapeter String
     */
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8', false);
    }

    /**
     * Generiert eine URL basierend auf der Route
     *
     * @param string $name Routenname
     * @param array $params Parameter für die Route
     * @return string Generierte URL
     */
    public function url(string $name, array $params = []): string
    {
        if ($this->router === null) {
            return '#no-router';
        }

        try {
            return $this->router->url($name, $params);
        } catch (\Throwable $e) {
            return '#route-error-' . $name;
        }
    }

    /**
     * Generiert eine URL zu einer Asset-Datei
     *
     * @param string $path Pfad zur Asset-Datei
     * @return string Asset-URL
     */
    public function asset(string $path): string
    {
        // Führenden Slash entfernen
        $path = ltrim($path, '/');

        // Basis-URL ermitteln
        $baseUrl = isset($_SERVER['REQUEST_SCHEME']) && isset($_SERVER['HTTP_HOST'])
            ? $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST']
            : '';

        return $baseUrl . '/assets/' . $path;
    }

    /**
     * Formatiert ein Datum
     *
     * @param mixed $date Datum als string, timestamp oder DateTime
     * @param string $format Datumsformat (PHP date() kompatibel)
     * @return string Formatiertes Datum
     */
    public function dateFormat(mixed $date, string $format = 'd.m.Y H:i'): string
    {
        if ($date === null) {
            return '';
        }

        if (is_numeric($date)) {
            $date = '@' . $date; // Timestamp
        }

        try {
            $datetime = $date instanceof \DateTimeInterface
                ? $date
                : new \DateTime($date);

            return $datetime->format($format);
        } catch (\Throwable $e) {
            return (string)$date;
        }
    }

    /**
     * Formatiert eine Zahl
     *
     * @param mixed $number Zu formatierende Zahl
     * @param int $decimals Anzahl der Nachkommastellen
     * @param string $decPoint Dezimalpunkt
     * @param string $thousandsSep Tausendertrennzeichen
     * @return string Formatierte Zahl
     */
    public function numberFormat(mixed $number, int $decimals = 2, string $decPoint = ',', string $thousandsSep = '.'): string
    {
        return number_format((float)$number, $decimals, $decPoint, $thousandsSep);
    }

    /**
     * Konvertiert Zeilenumbrüche in <br> Tags
     *
     * @param string $text Text mit Zeilenumbrüchen
     * @return string Text mit <br> Tags
     */
    public function nl2br(string $text): string
    {
        return nl2br($this->escape($text), false);
    }

    /**
     * Kürzt einen Text auf eine bestimmte Länge
     *
     * @param string $text Zu kürzender Text
     * @param int $length Maximale Länge
     * @param string $suffix Suffix für gekürzte Texte
     * @return string Gekürzter Text
     */
    public function truncate(string $text, int $length = 100, string $suffix = '...'): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $length)) . $suffix;
    }

    /**
     * Konvertiert Text in Kleinbuchstaben
     *
     * @param string $text Text
     * @return string Text in Kleinbuchstaben
     */
    public function lower(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    /**
     * Konvertiert Text in Großbuchstaben
     *
     * @param string $text Text
     * @return string Text in Großbuchstaben
     */
    public function upper(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }

    /**
     * Konvertiert den ersten Buchstaben eines Texts in einen Großbuchstaben
     *
     * @param string $text Text
     * @return string Text mit Großbuchstaben am Anfang
     */
    public function capitalize(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') .
            mb_substr($text, 1, null, 'UTF-8');
    }

    /**
     * Bildet Pluralformen basierend auf einer Anzahl
     *
     * @param int $count Anzahl
     * @param string $singular Singular-Form
     * @param string $plural Plural-Form
     * @return string Korrekte Form basierend auf der Anzahl
     */
    public function plural(int $count, string $singular, string $plural): string
    {
        return $count === 1 ? $singular : $plural;
    }

    /**
     * Debug-Funktion zur Anzeige von Variablen
     *
     * @param mixed $var Zu debuggende Variable
     * @return string HTML-formatierte Debug-Information
     */
    public function dump(mixed $var): string
    {
        ob_start();
        var_dump($var);
        $output = ob_get_clean();

        return '<pre>' . $this->escape($output) . '</pre>';
    }

    /**
     * Extrahiert einen Teil eines Arrays oder Strings
     *
     * @param array|string $input Array oder String
     * @param int $start Startposition
     * @param int|null $length Länge (optional)
     * @return array|string Extrahierter Teil
     */
    public function slice(array|string $input, int $start, ?int $length = null): array|string
    {
        if (is_array($input)) {
            return array_slice($input, $start, $length);
        }

        return mb_substr($input, $start, $length, 'UTF-8');
    }

    /**
     * Konvertiert einen Wert in einen JSON-String
     *
     * @param mixed $value Zu konvertierender Wert
     * @param int $options JSON-Optionen
     * @return string JSON-String
     */
    public function json(mixed $value, int $options = 0): string
    {
        return json_encode($value, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Konditionale Ausführung einer Funktion
     *
     * @param bool $condition Bedingung
     * @param mixed $value Wert, wenn Bedingung true ist
     * @param mixed $default Wert, wenn Bedingung false ist
     * @return mixed Ergebnis basierend auf der Bedingung
     */
    public function when(bool $condition, mixed $value, mixed $default = null): mixed
    {
        return $condition ? $value : $default;
    }

    /**
     * Konditionale Ausführung einer Funktion (negierte Bedingung)
     *
     * @param bool $condition Bedingung
     * @param mixed $value Wert, wenn Bedingung false ist
     * @param mixed $default Wert, wenn Bedingung true ist
     * @return mixed Ergebnis basierend auf der Bedingung
     */
    public function unless(bool $condition, mixed $value, mixed $default = null): mixed
    {
        return !$condition ? $value : $default;
    }

    /**
     * Erstellt eine klassenbasierte Zeichenfolge für HTML-Attribute
     *
     * @param array $classes Assoziatives Array mit Klassennamen als Schlüssel und bool Bedingungen als Werte
     * @return string CSS-Klassenattribut
     */
    public function class(array $classes): string
    {
        $activeClasses = [];

        foreach ($classes as $className => $condition) {
            if ($condition) {
                $activeClasses[] = $className;
            }
        }

        return implode(' ', $activeClasses);
    }
}