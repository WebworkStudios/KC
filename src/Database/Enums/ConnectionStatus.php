<?php

namespace Src\Database\Enums;

/**
 * Status einer Datenbankverbindung
 */
enum ConnectionStatus: string
{
    /**
     * Verbindung hergestellt und aktiv
     */
    case CONNECTED = 'connected';

    /**
     * Keine Verbindung hergestellt
     */
    case DISCONNECTED = 'disconnected';

    /**
     * Fehler bei der Verbindung
     */
    case ERROR = 'error';
}