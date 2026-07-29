<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $addresses->map($this->present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $address = Address::create(array_merge(
            $this->validateAddress($request),
            ['user_id' => $request->user()->id]
        ));

        return response()->json(['data' => $this->present($address)], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $this->assertOwned($request, $address);

        $address->fill($this->validateAddress($request))->save();

        return response()->json(['data' => $this->present($address->refresh())]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->assertOwned($request, $address);

        $address->delete();

        return response()->json(['message' => 'Address removed.']);
    }

    /** @return array<string, mixed> */
    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => ['sometimes', 'string', 'max:64'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['required', 'string', 'max:128'],
            'area' => ['nullable', 'string', 'max:128'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertOwned(Request $request, Address $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function present(Address $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'city' => $address->city,
            'area' => $address->area,
            'street' => $address->street,
            'building' => $address->building,
            'notes' => $address->notes,
            'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
            'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
            'is_default' => $address->is_default,
            'single_line' => $address->toSingleLine(),
        ];
    }
}
