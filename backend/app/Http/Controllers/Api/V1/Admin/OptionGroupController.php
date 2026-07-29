<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OptionGroupKind;
use App\Enums\OptionSelection;
use App\Http\Controllers\Api\V1\Concerns\HandlesApiQueries;
use App\Http\Controllers\Controller;
use App\Http\Resources\OptionGroupResource;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Variant and add-on groups, with their options edited as a single nested
 * document — a group without its options is never a valid state to save.
 */
class OptionGroupController extends Controller
{
    use HandlesApiQueries;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizePermission(Permissions::PRODUCTS_VIEW);

        $query = OptionGroup::query()
            ->with(['options', 'product:id,name_en,name_ar'])
            ->when($request->boolean('library_only'), fn ($q) => $q->library())
            ->when($request->query('product_id'), fn ($q, $id) => $q->where('product_id', $id));

        $this->applyFilters($query, $request, ['kind', 'selection', 'is_active', 'product_id']);
        $this->applySorting($query, $request, ['name_en', 'sort_order', 'created_at'], 'sort_order');

        return OptionGroupResource::collection(
            $query->paginate($this->perPage($request, 50, 100))->withQueryString()
        );
    }

    public function show(OptionGroup $optionGroup): OptionGroupResource
    {
        $this->authorizePermission(Permissions::PRODUCTS_VIEW);

        return new OptionGroupResource($optionGroup->load('options.image'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission(Permissions::PRODUCTS_MANAGE);

        $validated = $this->validateGroup($request);

        $group = DB::transaction(function () use ($validated) {
            $options = $validated['options'] ?? [];
            unset($validated['options']);

            $group = OptionGroup::create($validated);
            $this->syncOptions($group, $options);

            return $group;
        });

        return response()->json([
            'data' => (new OptionGroupResource($group->load('options')))->resolve(),
            'message' => 'Option group created.',
        ], 201);
    }

    public function update(Request $request, OptionGroup $optionGroup): JsonResponse
    {
        $this->authorizePermission(Permissions::PRODUCTS_MANAGE);

        $validated = $this->validateGroup($request, $optionGroup);

        DB::transaction(function () use ($optionGroup, $validated, $request) {
            $options = $validated['options'] ?? null;
            unset($validated['options']);

            $optionGroup->fill($validated)->save();

            if ($request->has('options')) {
                $this->syncOptions($optionGroup, $options ?? []);
            }
        });

        return response()->json([
            'data' => (new OptionGroupResource($optionGroup->fresh('options')))->resolve(),
            'message' => 'Option group updated.',
        ]);
    }

    public function destroy(OptionGroup $optionGroup): JsonResponse
    {
        $this->authorizePermission(Permissions::PRODUCTS_MANAGE);

        $optionGroup->delete();

        return response()->json(['message' => 'Option group deleted.']);
    }

    /** @return array<string, mixed> */
    private function validateGroup(Request $request, ?OptionGroup $group = null): array
    {
        $required = $group ? 'sometimes' : 'required';

        return $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'name_en' => [$required, 'string', 'max:255'],
            'name_ar' => [$required, 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:255'],
            'kind' => [$required, 'string', 'in:'.implode(',', OptionGroupKind::values())],
            'selection' => [$required, 'string', 'in:'.implode(',', OptionSelection::values())],
            'is_required' => ['sometimes', 'boolean'],
            'min_selections' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'max_selections' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],

            'options' => ['sometimes', 'array', 'max:40'],
            'options.*.id' => ['nullable', 'integer', 'exists:options,id'],
            'options.*.name_en' => ['required', 'string', 'max:255'],
            'options.*.name_ar' => ['required', 'string', 'max:255'],
            'options.*.price_delta' => ['required', 'numeric', 'min:-99999', 'max:99999999'],
            'options.*.image_id' => ['nullable', 'integer', 'exists:media,id'],
            'options.*.is_default' => ['sometimes', 'boolean'],
            'options.*.is_available' => ['sometimes', 'boolean'],
            'options.*.max_quantity' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
    }

    /**
     * Replaces the group's options with the submitted set, keeping ids stable
     * where they were provided so historic order lines still resolve.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    private function syncOptions(OptionGroup $group, array $options): void
    {
        $keptIds = [];

        foreach ($options as $index => $data) {
            $data['sort_order'] = $index + 1;
            $data['option_group_id'] = $group->id;

            $id = $data['id'] ?? null;
            unset($data['id']);

            $option = $id
                ? tap(Option::where('option_group_id', $group->id)->find($id))?->fill($data)
                : new Option($data);

            if (! $option) {
                continue;
            }

            $option->option_group_id = $group->id;
            $option->save();

            $keptIds[] = $option->id;
        }

        Option::where('option_group_id', $group->id)
            ->whereNotIn('id', $keptIds ?: [0])
            ->delete();
    }
}
