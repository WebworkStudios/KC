<?php


namespace Src\Database\Cache;

/**
 * In-Memory-Cache-Implementierung mit Array-Speicherung
 *
 * Einfacher Cache für Tests oder temporäre Daten innerhalb einer Request-Lebensdauer
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiry: int|null, tags: array<string>}> Gespeicherte Cache-Einträge */
    private array $items = [];

    /** @var array<string, array<string>> Mapping von Tags zu Cache-Schlüsseln */
    private array $tags = [];

    /**
     * {@inheritDoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool
    {
        // Ablaufzeit berechnen
        $expiry = $ttl !== null ? time() + $ttl : null;

        // Wert speichern
        $this->items[$key] = [
            'value' => $value,
            'expiry' => $expiry,
            'tags' => $tags
        ];

        // Tags aktualisieren
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            if (!in_array($key, $this->tags[$tag])) {
                $this->tags[$tag][] = $key;
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $key): mixed
    {
        // Prüfen, ob der Schlüssel existiert und nicht abgelaufen ist
        if (!$this->has($key)) {
            return null;
        }

        return $this->items[$key]['value'];
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        // Prüfen, ob der Schlüssel existiert
        if (!isset($this->items[$key])) {
            return false;
        }

        // Prüfen, ob der Eintrag abgelaufen ist
        $expiry = $this->items[$key]['expiry'];
        if ($expiry !== null && $expiry < time()) {
            // Abgelaufenen Eintrag löschen
            $this->delete($key);
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $key): bool
    {
        if (!isset($this->items[$key])) {
            return false;
        }

        // Tags aktualisieren
        foreach ($this->items[$key]['tags'] as $tag) {
            if (isset($this->tags[$tag])) {
                $index = array_search($key, $this->tags[$tag]);
                if ($index !== false) {
                    unset($this->tags[$tag][$index]);
                    $this->tags[$tag] = array_values($this->tags[$tag]);
                }

                // Tag entfernen, wenn keine zugehörigen Schlüssel mehr vorhanden sind
                if (empty($this->tags[$tag])) {
                    unset($this->tags[$tag]);
                }
            }
        }

        // Eintrag entfernen
        unset($this->items[$key]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): bool
    {
        $this->items = [];
        $this->tags = [];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateByTag(string $tag): bool
    {
        if (!isset($this->tags[$tag])) {
            return false;
        }

        // Alle Einträge mit diesem Tag löschen
        foreach ($this->tags[$tag] as $key) {
            $this->delete($key);
        }

        // Tag aus der Verwaltung entfernen
        unset($this->tags[$tag]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        // Prüfen, ob der Wert bereits im Cache ist
        if ($this->has($key)) {
            return $this->get($key);
        }

        // Wert generieren
        $value = $callback();

        // Im Cache speichern
        $this->set($key, $value, $ttl, $tags);

        return $value;
    }

    /**
     * Entfernt abgelaufene Einträge aus dem Cache
     *
     * @return int Anzahl der entfernten Einträge
     */
    public function cleanup(): int
    {
        $count = 0;
        $now = time();

        foreach ($this->items as $key => $item) {
            $expiry = $item['expiry'];
            if ($expiry !== null && $expiry < $now) {
                $this->delete($key);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Gibt die Anzahl der Cache-Einträge zurück
     *
     * @return int Anzahl der Einträge
     */
    public function count(): int
    {
        return count($this->items);
    }
}