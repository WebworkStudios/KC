<?php

namespace Src\Database\Enums;

/**
 * Sortierrichtungen für ORDER BY
 */
enum OrderDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}