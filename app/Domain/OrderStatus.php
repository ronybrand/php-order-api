<?php

declare(strict_types=1);

namespace App\Domain;

/** Lifecycle of an order: OPEN -> CONFIRMED -> CANCELED, or OPEN -> CANCELED. */
enum OrderStatus: string
{
    case Open = 'OPEN';
    case Confirmed = 'CONFIRMED';
    case Canceled = 'CANCELED';
}
