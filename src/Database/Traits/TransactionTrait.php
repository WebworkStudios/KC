<?php

namespace Src\Database\Traits;

use Closure;
use PDOException;
use Src\Database\Exceptions\TransactionException;
use Throwable;

/**
 * Trait für Transaktions-Support im QueryBuilder
 */
trait TransactionTrait
{
    /**
     * Führt eine Closure innerhalb einer Transaktion aus
     *
     * @param Closure $callback Auszuführender Code
     * @return mixed Rückgabewert der Closure
     * @throws TransactionException Bei Fehlern mit der Transaktion
     * @throws Throwable Bei Fehlern im Callback
     */
    public function transaction(Closure $callback): mixed
    {
        $connection = $this->connectionManager->getConnection($this->connectionName, true);

        // Verschachtelter Transaktionsaufruf - direkter Callback-Aufruf ohne neue Transaktion
        if ($connection->inTransaction()) {
            $this->logger->debug("Verschachtelter Transaktionsaufruf - verwende bestehende Transaktion", [
                'connection' => $this->connectionName
            ]);

            return $callback($this);
        }

        // Neue Transaktion starten
        $this->beginTransaction();

        try {
            // Callback ausführen
            $result = $callback($this);

            // Transaktion committen
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            // Bei Fehlern Rollback durchführen
            $this->rollback();

            $this->logger->error("Fehler in Transaktion, Rollback durchgeführt", [
                'connection' => $this->connectionName,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);

            throw $e;
        }
    }

    /**
     * Überprüft, ob eine Transaktion aktiv ist
     *
     * @return bool True, wenn eine Transaktion aktiv ist
     */
    public function inTransaction(): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);
            return $connection->inTransaction();
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Prüfen des Transaktionsstatus", [
                'connection' => $this->connectionName,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Beginnt eine neue Transaktion
     *
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function beginTransaction(): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if ($connection->inTransaction()) {
                $this->logger->warning("Versuch, eine bereits laufende Transaktion zu starten", [
                    'connection' => $this->connectionName
                ]);
                return false;
            }

            $result = $connection->beginTransaction();

            $this->logger->debug("Transaktion gestartet", [
                'connection' => $this->connectionName,
                'success' => $result
            ]);

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Starten der Transaktion", [
                'connection' => $this->connectionName,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Starten der Transaktion: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Übergibt (Commit) eine Transaktion
     *
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function commit(): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if (!$connection->inTransaction()) {
                $this->logger->warning("Versuch, eine nicht existierende Transaktion zu committen", [
                    'connection' => $this->connectionName
                ]);
                return false;
            }

            $result = $connection->commit();

            $this->logger->debug("Transaktion erfolgreich committed", [
                'connection' => $this->connectionName,
                'success' => $result
            ]);

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Committen der Transaktion", [
                'connection' => $this->connectionName,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Committen der Transaktion: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Macht eine Transaktion rückgängig (Rollback)
     *
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function rollback(): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if (!$connection->inTransaction()) {
                $this->logger->warning("Versuch, eine nicht existierende Transaktion zurückzurollen", [
                    'connection' => $this->connectionName
                ]);
                return false;
            }

            $result = $connection->rollBack();

            $this->logger->debug("Transaktion zurückgerollt", [
                'connection' => $this->connectionName,
                'success' => $result
            ]);

            return $result;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Rollback der Transaktion", [
                'connection' => $this->connectionName,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Rollback der Transaktion: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Führt einen Savepoint für komplexe Transaktionen durch
     *
     * @param string $name Name des Savepoints
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function savepoint(string $name): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if (!$connection->inTransaction()) {
                $this->logger->warning("Versuch, einen Savepoint außerhalb einer Transaktion zu setzen", [
                    'connection' => $this->connectionName,
                    'savepoint' => $name
                ]);
                return false;
            }

            $escapedName = $this->escapeSavepointName($name);
            $connection->exec("SAVEPOINT {$escapedName}");

            $this->logger->debug("Savepoint gesetzt", [
                'connection' => $this->connectionName,
                'savepoint' => $name
            ]);

            return true;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Setzen des Savepoints", [
                'connection' => $this->connectionName,
                'savepoint' => $name,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Setzen des Savepoints '{$name}': {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Escapet den Namen eines Savepoints für die sichere Verwendung in SQL
     *
     * @param string $name Savepoint-Name
     * @return string Escapeter Name
     */
    private function escapeSavepointName(string $name): string
    {
        // Nur alphanumerische Zeichen und Unterstriche erlauben
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }

    /**
     * Führt einen Rollback zu einem Savepoint durch
     *
     * @param string $name Name des Savepoints
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function rollbackToSavepoint(string $name): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if (!$connection->inTransaction()) {
                $this->logger->warning("Versuch, zu einem Savepoint zurückzukehren außerhalb einer Transaktion", [
                    'connection' => $this->connectionName,
                    'savepoint' => $name
                ]);
                return false;
            }

            $escapedName = $this->escapeSavepointName($name);
            $connection->exec("ROLLBACK TO SAVEPOINT {$escapedName}");

            $this->logger->debug("Zu Savepoint zurückgekehrt", [
                'connection' => $this->connectionName,
                'savepoint' => $name
            ]);

            return true;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Zurückkehren zum Savepoint", [
                'connection' => $this->connectionName,
                'savepoint' => $name,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Zurückkehren zum Savepoint '{$name}': {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Hebt einen Savepoint auf (Release)
     *
     * @param string $name Name des Savepoints
     * @return bool True bei Erfolg
     * @throws TransactionException Bei Fehlern mit der Transaktion
     */
    public function releaseSavepoint(string $name): bool
    {
        try {
            $connection = $this->connectionManager->getConnection($this->connectionName, true);

            if (!$connection->inTransaction()) {
                $this->logger->warning("Versuch, einen Savepoint außerhalb einer Transaktion freizugeben", [
                    'connection' => $this->connectionName,
                    'savepoint' => $name
                ]);
                return false;
            }

            $escapedName = $this->escapeSavepointName($name);
            $connection->exec("RELEASE SAVEPOINT {$escapedName}");

            $this->logger->debug("Savepoint freigegeben", [
                'connection' => $this->connectionName,
                'savepoint' => $name
            ]);

            return true;
        } catch (PDOException $e) {
            $this->logger->error("Fehler beim Freigeben des Savepoints", [
                'connection' => $this->connectionName,
                'savepoint' => $name,
                'error' => $e->getMessage()
            ]);

            throw new TransactionException(
                "Fehler beim Freigeben des Savepoints '{$name}': {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }
}