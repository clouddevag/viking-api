<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OfferController extends Controller
{
    /** Live offers for the home screen carousel. */
    public function index(): AnonymousResourceCollection
    {
        $offers = Offer::query()
            ->live()
            ->ordered()
            ->with(['image', 'products.image', 'products.category'])
            ->get();

        return OfferResource::collection($offers);
    }

    public function show(string $slug): OfferResource
    {
        $offer = Offer::query()
            ->live()
            ->with(['image', 'products.image', 'products.category'])
            ->where('slug', $slug)
            ->firstOrFail();

        return new OfferResource($offer);
    }
}
