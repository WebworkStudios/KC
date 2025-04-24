<?php

declare(strict_types=1);

namespace App\Core\Database\Exceptions;

use PDOException;

/**
 * Exception, die geworfen wird, wenn eine SQL-Query fehlschlägt.
 */
class QueryException extends DatabaseException
{
    /**
     * Die PDOException, die die ursprüngliche Fehlermeldung enthält.
     */
    private PDOException $pdoException;

    /**
     * Konstruktor
     *
     * @param string $message Fehlermeldung
     * @param int $code Fehlercode
     * @param PDOException $pdoException Die ursprüngliche PDOException
     * @param string $query Die SQL-Query, die den Fehler verursacht hat
     * @param array $params Die Parameter, die für die Query verwendet wurden
     */
    public function __construct(
        string       $message,
        int          $code,
        PDOException $pdoException,
        string       $query,
        array        $params = []
    )
    {
        parent::__construct($message, $code, $pdoException, $query, $params);
        $this->pdoException = $pdoException;
    }

    /**
     * Gibt die ursprüngliche PDOException zurück
     *
     * @return PDOException
     */
    public function getPdoException(): PDOException
    {
        return $this->pdoException;
    }
}