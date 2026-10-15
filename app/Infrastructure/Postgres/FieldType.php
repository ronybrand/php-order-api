<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

enum FieldType
{
    case String;
    case Uuid;
    case Integer;
    case Time;
    case Enum;
}
