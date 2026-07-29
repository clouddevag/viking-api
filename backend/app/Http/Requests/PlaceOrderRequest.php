<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Checkout. Extends the cart rules with the customer details each order type
 * requires — a delivery needs an address, a dine-in needs a table.
 */
class PlaceOrderRequest extends PriceCartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'type' => ['required', Rule::in(OrderType::values())],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{6,32}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:50'],

            'table_token' => ['nullable', 'string', 'max:64'],
            'table_session_token' => ['nullable', 'string', 'max:64'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('type');

            if ($type === OrderType::Delivery->value && ! $this->filled('delivery_address')) {
                $validator->errors()->add('delivery_address', 'A delivery address is required.');
            }

            // A phone number is the only way to reach a guest about a takeaway
            // or delivery order.
            if (in_array($type, [OrderType::Delivery->value, OrderType::Takeaway->value], true)
                && ! $this->user()
                && ! $this->filled('customer_phone')) {
                $validator->errors()->add('customer_phone', 'A contact phone number is required.');
            }
        });
    }

    /** @return array<string, mixed> */
    public function orderAttributes(): array
    {
        return [
            'customer_name' => $this->validated('customer_name'),
            'customer_phone' => $this->validated('customer_phone'),
            'notes' => $this->validated('notes'),
            'guest_count' => $this->validated('guest_count'),
            'delivery_address' => $this->validated('delivery_address'),
        ];
    }
}
