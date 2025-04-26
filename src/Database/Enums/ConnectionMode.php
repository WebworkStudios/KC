<?php


namespace Src\Database\Enums;

/**
 * Modus für Datenbankverbindungen
 */
enum ConnectionMode
{
    /**
     * Nur-Lese-Modus (für SELECT-Abfragen)
     */
    case READ;

    /**
     * Schreib-Modus (für INSERT, UPDATE, DELETE, etc.)
     */
    case WRITE;
}