<?php

declare(strict_types=1);

namespace App\Exceptions;

class PaymentException extends DomainException
{
    protected int $statusCode = 422;

    protected string $errorCode = 'payment_failed';

    public static function alreadySettled(string $orderNumber): self
    {
        return new self("Order {$orderNumber} is already fully paid.", [
            'order_number' => $orderNumber,
        ]);
    }

    public static function amountExceedsOutstanding(float $amount, float $outstanding): self
    {
        return new self('The payment amount is greater than the outstanding balance.', [
            'amount' => $amount,
            'outstanding' => $outstanding,
        ]);
    }

    public static function insufficientTender(float $tendered, float $amount): self
    {
        return new self('The tendered amount is less than the amount due.', [
            'tendered' => $tendered,
            'due' => $amount,
        ]);
    }

    public static function refundExceedsPaid(float $amount, float $refundable): self
    {
        return new self('The refund is greater than the refundable amount.', [
            'amount' => $amount,
            'refundable' => $refundable,
        ]);
    }

    public static function nothingToRefund(string $orderNumber): self
    {
        return new self("Order {$orderNumber} has no captured payment to refund.", [
            'order_number' => $orderNumber,
        ]);
    }
}
