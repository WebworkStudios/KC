<?php


namespace Src\Queue;

use DateTime;
use Exception;

/**
 * Parser für Cron-Expressions
 */
class CronParser
{
    /** @var array Definitionen für Textausdrücke */
    private array $expressions = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
        '@minutely' => '* * * * *',
    ];

    /**
     * Prüft, ob ein Cron-Ausdruck gültig ist
     *
     * @param string $expression Cron-Ausdruck
     * @return bool True, wenn der Ausdruck gültig ist
     */
    public function isValid(string $expression): bool
    {
        // Text-Ausdrücke umwandeln
        if (isset($this->expressions[$expression])) {
            $expression = $this->expressions[$expression];
        }

        // Standarddefinition mit 5 Segmenten: Minute, Stunde, Tag, Monat, Wochentag
        $segments = explode(' ', $expression);

        if (count($segments) !== 5) {
            return false;
        }

        // Jedes Segment überprüfen
        $patterns = [
            'minute' => '/^(\*|[0-5]?[0-9](-[0-5]?[0-9])?)(\/[0-9]+)?(,(\*|[0-5]?[0-9](-[0-5]?[0-9])?)(\/[0-9]+)?)*$/',
            'hour' => '/^(\*|1?[0-9]|2[0-3])((-1?[0-9])|(-2[0-3]))?(\/[0-9]+)?(,(\*|1?[0-9]|2[0-3])((-1?[0-9])|(-2[0-3]))?(\/[0-9]+)?)*$/',
            'day' => '/^(\*|[1-9]|[12][0-9]|3[01])((-[1-9])|(-[12][0-9])|(-3[01]))?(\/[0-9]+)?(,(\*|[1-9]|[12][0-9]|3[01])((-[1-9])|(-[12][0-9])|(-3[01]))?(\/[0-9]+)?)*$/',
            'month' => '/^(\*|[1-9]|1[0-2])((-[1-9])|(-1[0-2]))?(\/[0-9]+)?(,(\*|[1-9]|1[0-2])((-[1-9])|(-1[0-2]))?(\/[0-9]+)?)*$/',
            'weekday' => '/^(\*|[0-6])((-[0-6]))?(\/[0-9]+)?(,(\*|[0-6])((-[0-6]))?(\/[0-9]+)?)*$/',
        ];

        foreach (array_keys($patterns) as $i => $name) {
            if (!preg_match($patterns[$name], $segments[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prüft, ob ein Job basierend auf dem Cron-Ausdruck fällig ist
     *
     * @param string $expression Cron-Ausdruck
     * @param DateTime $lastRun Zeitpunkt der letzten Ausführung
     * @param DateTime|null $now Aktueller Zeitpunkt (oder null für jetzt)
     * @return bool True, wenn der Job fällig ist
     */
    public function isDue(string $expression, DateTime $lastRun, ?DateTime $now = null): bool
    {
        $now = $now ?? new DateTime();

        // Text-Ausdrücke umwandeln
        if (isset($this->expressions[$expression])) {
            $expression = $this->expressions[$expression];
        }

        $segments = explode(' ', $expression);
        if (count($segments) !== 5) {
            return false;
        }

        // Einfache Implementierung: Prüfen, ob die nächste Ausführungszeit
        // zwischen dem letzten Lauf und jetzt liegt
        $nextRun = $this->getNextRunDate($expression, $lastRun);

        return $nextRun <= $now;
    }

    /**
     * Berechnet den nächsten Ausführungszeitpunkt
     *
     * @param string $expression Cron-Ausdruck
     * @param DateTime $from Startzeitpunkt für die Berechnung
     * @return DateTime Nächster Ausführungszeitpunkt
     */
    public function getNextRunDate(string $expression, DateTime $from): DateTime
    {
        // Text-Ausdrücke umwandeln
        if (isset($this->expressions[$expression])) {
            $expression = $this->expressions[$expression];
        }

        $segments = explode(' ', $expression);
        if (count($segments) !== 5) {
            throw new Exception("Ungültiger Cron-Ausdruck: $expression");
        }

        // Cron-Segmente parsen
        list($minute, $hour, $day, $month, $weekday) = $segments;

        // Nächsten Ausführungszeitpunkt berechnen
        $next = clone $from;
        $next->modify('+1 minute');
        $next->setTime($next->format('H'), $next->format('i'), 0);

        while (!$this->matchesCron($next, $minute, $hour, $day, $month, $weekday)) {
            $next->modify('+1 minute');
        }

        return $next;
    }

    /**
     * Prüft, ob ein Zeitpunkt einem Cron-Ausdruck entspricht
     *
     * @param DateTime $date Zu prüfender Zeitpunkt
     * @param string $minute Minuten-Segment
     * @param string $hour Stunden-Segment
     * @param string $day Tag-Segment
     * @param string $month Monats-Segment
     * @param string $weekday Wochentag-Segment
     * @return bool True, wenn der Zeitpunkt dem Ausdruck entspricht
     */
    private function matchesCron(DateTime $date, string $minute, string $hour, string $day, string $month, string $weekday): bool
    {
        $dateMinute = (int)$date->format('i');
        $dateHour = (int)$date->format('H');
        $dateDay = (int)$date->format('d');
        $dateMonth = (int)$date->format('m');
        $dateWeekday = (int)$date->format('w');

        return $this->matchesSegment($dateMinute, $minute, 0, 59)
            && $this->matchesSegment($dateHour, $hour, 0, 23)
            && $this->matchesSegment($dateDay, $day, 1, 31)
            && $this->matchesSegment($dateMonth, $month, 1, 12)
            && $this->matchesSegment($dateWeekday, $weekday, 0, 6);
    }

    /**
     * Prüft, ob ein Wert einem Cron-Segment entspricht
     *
     * @param int $value Zu prüfender Wert
     * @param string $segment Cron-Segment
     * @param int $min Minimaler gültiger Wert
     * @param int $max Maximaler gültiger Wert
     * @return bool True, wenn der Wert dem Segment entspricht
     */
    private function matchesSegment(int $value, string $segment, int $min, int $max): bool
    {
        // Sternchen bedeutet jeder Wert
        if ($segment === '*') {
            return true;
        }

        // Komma-getrennte Liste
        if (strpos($segment, ',') !== false) {
            $parts = explode(',', $segment);
            foreach ($parts as $part) {
                if ($this->matchesSegment($value, $part, $min, $max)) {
                    return true;
                }
            }
            return false;
        }

        // Schrittweite (z.B. */5)
        if (strpos($segment, '/') !== false) {
            list($range, $step) = explode('/', $segment);

            $step = (int)$step;
            if ($step <= 0) {
                return false;
            }

            if ($range === '*') {
                return $value % $step === 0;
            }

            // Bereich mit Schrittweite
            if (strpos($range, '-') !== false) {
                list($start, $end) = explode('-', $range);
                $start = (int)$start;
                $end = (int)$end;

                if ($value < $start || $value > $end) {
                    return false;
                }

                return ($value - $start) % $step === 0;
            }

            // Einzelwert mit Schrittweite
            $start = (int)$range;
            return $value >= $start && ($value - $start) % $step === 0;
        }

        // Bereich (z.B. 1-5)
        if (strpos($segment, '-') !== false) {
            list($start, $end) = explode('-', $segment);
            $start = (int)$start;
            $end = (int)$end;

            return $value >= $start && $value <= $end;
        }

        // Einzelner Wert
        return $value === (int)$segment;
    }
}