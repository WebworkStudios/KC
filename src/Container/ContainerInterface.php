<?php


declare(strict_types=1);

namespace Src\Core\Container;

/**
 * Beschreibt einen PSR-11 kompatiblen Container
 */
interface ContainerInterface
{
    /**
     * Findet einen Eintrag mit der gegebenen Kennung und gibt ihn zurück.
     *
     * @param string $id Identifier des Eintrags
     * @return mixed Eintrag
     * @throws ContainerExceptionInterface Fehler bei der Auflösung des Eintrags
     * @throws NotFoundExceptionInterface  Eintrag nicht gefunden
     */
    public function get(string $id): mixed;

    /**
     * Gibt zurück, ob der Container einen Eintrag für den gegebenen Identifier besitzt.
     *
     * @param string $id Identifier des Eintrags
     * @return bool
     */
    public function has(string $id): bool;
}