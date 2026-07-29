<?php

declare(strict_types=1);

namespace App\Exceptions;

class CouponException extends DomainException
{
    protected int $statusCode = 422;

    protected string $errorCode = 'coupon_invalid';

    public static function notFound(string $code): self
    {
        return new self('This coupon code is not valid.', ['code' => $code]);
    }

    public static function inactive(string $code): self
    {
        return new self('This coupon is no longer active.', ['code' => $code]);
    }

    public static function notStarted(string $code): self
    {
        return new self('This coupon is not available yet.', ['code' => $code]);
    }

    public static function expired(string $code): self
    {
        return new self('This coupon has expired.', ['code' => $code]);
    }

    public static function usageLimitReached(string $code): self
    {
        return new self('This coupon has reached its usage limit.', ['code' => $code]);
    }

    public static function userLimitReached(string $code): self
    {
        return new self('You have already used this coupon the maximum number of times.', [
            'code' => $code,
        ]);
    }

    public static function firstOrderOnly(string $code): self
    {
        return new self('This coupon is only valid on a first order.', ['code' => $code]);
    }

    public static function minimumNotMet(string $code, float $minimum): self
    {
        return new self('Your order does not reach the minimum for this coupon.', [
            'code' => $code,
            'minimum_order_amount' => $minimum,
        ]);
    }

    public static function noEligibleItems(string $code): self
    {
        return new self('This coupon does not apply to any item in your cart.', ['code' => $code]);
    }
}
