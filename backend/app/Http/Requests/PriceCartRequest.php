<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\CartLineInput;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation only. Whether the products exist, are available and satisfy
 * their option rules is the pricing service's job — doing it here too would
 * duplicate the rules in a place that cannot be reused by the seeder or tests.
 */
class PriceCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxQuantity = (int) config('viking.max_item_quantity', 50);
        $maxLines = (int) config('viking.max_order_lines', 60);

        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'type' => ['sometimes', Rule::in(OrderType::values())],
            'coupon_code' => ['nullable', 'string', 'max:64'],

            'items' => ['required', 'array', 'min:1', "max:{$maxLines}"],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1', "max:{$maxQuantity}"],
            'items.*.special_instructions' => ['nullable', 'string', 'max:500'],
            'items.*.options' => ['sometimes', 'array', 'max:20'],
            'items.*.options.*.option_id' => ['required', 'integer', 'min:1'],
            'items.*.options.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }

    /** @return array<int, CartLineInput> */
    public function lines(): array
    {
        return array_map(
            static fn (array $item) => CartLineInput::fromArray($item),
            $this->validated('items')
        );
    }

    public function orderType(): OrderType
    {
        return OrderType::from($this->validated('type', OrderType::DineIn->value));
    }
}
