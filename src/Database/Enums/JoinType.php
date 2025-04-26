<?php


namespace Src\Database\Enums;

/**
 * Typen von JOIN-Operationen
 */
enum JoinType: string
{
    case INNER = 'INNER JOIN';
    case LEFT = 'LEFT JOIN';
    case RIGHT = 'RIGHT JOIN';
    case FULL = 'FULL JOIN';
    case CROSS = 'CROSS JOIN';
}