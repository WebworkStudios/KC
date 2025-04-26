<?php

namespace Src\Database;

/**
 * Strategien für Loadbalancing zwischen mehreren Datenbankservern
 */
enum LoadBalancingStrategy
{
    /**
     * Zufällige Auswahl eines Servers
     */
    case RANDOM;

    /**
     * Rotierender Aufruf aller Server (Round-Robin)
     */
    case ROUND_ROBIN;

    /**
     * Server mit den wenigsten aktiven Verbindungen
     */
    case LEAST_CONNECTIONS;
}