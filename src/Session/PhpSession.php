<?php


namespace Src\Session;

/**
 * Standard-PHP-Session-Implementierung
 *
 * Verwendet die nativen PHP-Session-Funktionen
 */
class PhpSession extends AbstractSession
{
    /**
     * Schließt die Session, ermöglicht aber weiterhin Lesezugriffe
     *
     * Nützlich für Operationen, die keine Schreibzugriffe erfordern, um Session-Locking zu vermeiden
     *
     * @return bool True bei Erfolg
     */
    public function closeAndKeepUsing(): bool
    {
        if (!$this->started) {
            return false;
        }

        // Im Read-Only-Modus ist die Session bereits geschlossen
        if ($this->readOnly) {
            return true;
        }

        // Session schließen, aber im Code weiterhin verwenden
        session_write_close();
        $this->readOnly = true;

        $this->logger->debug('Session geschlossen (Read-Only-Modus)');

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function startSession(): bool
    {
        // Prüfen, ob Header bereits gesendet wurden
        if (headers_sent($file, $line)) {
            $this->logger->warning('Session konnte nicht gestartet werden, da Header bereits gesendet wurden', [
                'file' => $file,
                'line' => $line
            ]);

            return false;
        }

        // Im Read-Only-Modus und PHP >= 7.0 können wir session_start() mit Optionen aufrufen
        if (PHP_VERSION_ID >= 70000) {
            $options = [
                'read_and_close' => $this->readOnly
            ];

            return session_start($options);
        }

        // Im Read-Only-Modus mit PHP < 7.0 müssen wir die Session nach dem Lesen schließen
        $result = session_start();

        if ($result && $this->readOnly) {
            session_write_close();
            // Die Session bleibt aber für Lesezugriffe geöffnet
        }

        return $result;
    }
}