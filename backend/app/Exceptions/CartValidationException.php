<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The submitted cart cannot be priced: an item is unavailable, a required
 * option group was not satisfied, or a selection breaks the group's rules.
 */
class CartValidationException extends DomainException
{
    protected int $statusCode = 422;

    protected string $errorCode = 'cart_invalid';

    public static function productUnavailable(string $name, int $productId): self
    {
        return new self("The item \"{$name}\" is not available right now.", [
            'product_id' => $productId,
        ]);
    }

    public static function productMissing(int $productId): self
    {
        return new self('One of the items in your cart no longer exists.', [
            'product_id' => $productId,
        ]);
    }

    public static function optionNotAllowed(int $optionId, int $productId): self
    {
        return new self('A selected option does not belong to this item.', [
            'option_id' => $optionId,
            'product_id' => $productId,
        ]);
    }

    public static function optionUnavailable(string $name, int $optionId): self
    {
        return new self("The option \"{$name}\" is sold out.", [
            'option_id' => $optionId,
        ]);
    }

    public static function selectionsTooFew(string $group, int $min, int $groupId): self
    {
        return new self("Please choose at least {$min} option(s) for \"{$group}\".", [
            'option_group_id' => $groupId,
            'minimum' => $min,
        ]);
    }

    public static function selectionsTooMany(string $group, int $max, int $groupId): self
    {
        return new self("You can choose at most {$max} option(s) for \"{$group}\".", [
            'option_group_id' => $groupId,
            'maximum' => $max,
        ]);
    }

    public static function empty(): self
    {
        return new self('Your cart is empty.');
    }

    public static function tooManyLines(int $max): self
    {
        return new self("An order cannot contain more than {$max} different items.", [
            'maximum' => $max,
        ]);
    }
}
