<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderStatus;

class OrderTransitionException extends DomainException
{
    protected int $statusCode = 409;

    protected string $errorCode = 'invalid_transition';

    public static function illegal(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            "An order cannot move from \"{$from->value}\" to \"{$to->value}\".",
            [
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => array_map(fn (OrderStatus $s) => $s->value, $from->allowedTransitions()),
            ]
        );
    }

    public static function terminal(OrderStatus $status): self
    {
        return new self("This order is already {$status->value} and cannot be changed.", [
            'status' => $status->value,
        ]);
    }
}
