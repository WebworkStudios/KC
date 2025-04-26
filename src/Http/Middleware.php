<?php

namespace Src\Http;

/**
 * Interface für HTTP-Middleware
 */
interface Middleware
{
    /**
     * Verarbeitet einen Request
     *
     * @param Request $request Zu verarbeitender Request
     * @param callable $next Nächste Middleware in der Kette
     * @return Response|null Response oder null, wenn die Verarbeitung fortgesetzt werden soll
     */
    public function process(Request $request, callable $next): ?Response;
}