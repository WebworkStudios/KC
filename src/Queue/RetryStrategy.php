<?php

namespace Src\Queue;

/**
 * Strategien für Wiederholungsversuche bei fehlgeschlagenen Jobs
 */
enum RetryStrategy
{
    /**
     * Feste Verzögerung
     *
     * Jeder Wiederholungsversuch hat die gleiche Verzögerung
     * Beispiel: 60s, 60s, 60s
     */
    case FIXED;

    /**
     * Lineare Verzögerung
     *
     * Die Verzögerung wächst linear mit der Anzahl der Versuche
     * Beispiel: 60s, 120s, 180s
     */
    case LINEAR;

    /**
     * Exponentielle Verzögerung
     *
     * Die Verzögerung wächst exponentiell mit der Anzahl der Versuche
     * Beispiel: 60s, 120s, 240s
     */
    case EXPONENTIAL;
}