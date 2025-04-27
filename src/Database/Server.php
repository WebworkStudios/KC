<?php

namespace Src\Database;

/**
 * Repräsentiert einen MySQL-Datenbankserver
 */
class Server
{
    /**
     * Erstellt einen neuen Server
     *
     * @param string $name Name des Servers (eindeutiger Bezeichner)
     * @param string $host Hostname oder IP-Adresse
     * @param string $username Benutzername für die Verbindung
     * @param string $password Passwort für die Verbindung
     * @param int $port Datenbank-Port (Standard: 3306)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly int    $port = 3306
    )
    {
    }

    /**
     * Gibt den Namen des Servers zurück
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gibt den Hostnamen zurück
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Gibt den Benutzernamen zurück
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Gibt das Passwort zurück
     *
     * @return string
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Gibt den Port zurück
     *
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }
}