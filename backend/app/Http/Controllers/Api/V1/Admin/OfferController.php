<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DiscountType;
use App\Enums\OfferType;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::OFFERS_MANAGE);

        $query = Offer::query()->with(['image', 'products:id']);

        $this->applyFilters($query, $request, ['type', 'is_active']);
        $this->applySorting($query, $request, ['sort_order', 'starts_at', 'ends_at', 'created_at'], 'sort_order');

        return OfferResource::collection(
            $query->paginate($this->perPage($request, 25, 100))->withQueryString()
        );
    }

    public function show(Offer $offer): OfferResource
    {
        $this->authorizePermission(Permissions::OFFERS_MANAGE);

        return new OfferResource($offer->load(['image', 'products.image']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::OFFERS_MANAGE);

        $validated = $this->validateOffer($request);
        $validated['slug'] ??= $this->uniqueSlug($validated['title_en']);

        $productIds = $validated['product_ids'] ?? [];
        unset($validated['product_ids']);

        $offer = Offer::create($validated);
        $offer->products()->sync($this->pivot($productIds));

        return response()->json([
            'data' => (new OfferResource($offer->load('image')))->resolve(),
            'message' => 'Offer created.',
        ], 201);
    }

    public function update(Request $request, Offer $offer): JsonResponse
    {
        $this->authorizePermission(Permissions::OFFERS_MANAGE);

        $validated = $this->validateOffer($request, $offer);

        $hasProducts = array_key_exists('product_ids', $validated);
        $productIds = $validated['product_ids'] ?? [];
        unset($validated['product_ids']);

        $offer->fill($validated)->save();

        if ($hasProducts) {
            $offer->products()->sync($this->pivot($productIds));
        }

        return response()->json([
            'data' => (new OfferResource($offer->fresh(['image', 'products'])))->resolve(),
            'message' => 'Offer updated.',
        ]);
    }

    public function destroy(Offer $offer): JsonResponse
    {
        $this->authorizePermission(Permissions::OFFERS_MANAGE);

        $offer->delete();

        return response()->json(['message' => 'Offer archived.']);
    }

    /** @return array<string, mixed> */
    private function validateOffer(Request $request, ?Offer $offer = null): array
    {
        $required = $offer ? 'sometimes' : 'required';

        return $request->validate([
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('offers', 'slug')->ignore($offer?->id)],
            'title_en' => [$required, 'string', 'max:255'],
            'title_ar' => [$required, 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            'badge_en' => ['nullable', 'string', 'max:64'],
            'badge_ar' => ['nullable', 'string', 'max:64'],
            'image_id' => ['nullable', 'integer', 'exists:media,id'],
            'type' => [$required, 'string', 'in:'.implode(',', OfferType::values())],
            'discount_type' => ['nullable', 'string', 'in:'.implode(',', DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'combo_price' => ['nullable', 'numeric', 'min:0'],
            'cta_url' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'product_ids' => ['sometimes', 'array', 'max:100'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, int>>
     */
    private function pivot(array $productIds): array
    {
        return collect($productIds)
            ->unique()
            ->mapWithKeys(fn ($id) => [$id => ['quantity' => 1]])
            ->all();
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: Str::lower(Str::random(8));
        $slug = $base;
        $suffix = 2;

        while (Offer::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
