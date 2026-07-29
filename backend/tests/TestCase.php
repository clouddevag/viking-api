<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\OptionGroupKind;
use App\Enums\OptionSelection;
use App\Models\Branch;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Builders for the fixture data most tests need.
     *
     * Deliberately explicit rather than factory-driven: the option group rules
     * are what most of these tests are about, so a test reads better when the
     * group it exercises is visible in its own setup than when it is hidden
     * behind a factory state.
     */
    protected function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    protected function makeBranch(array $attributes = []): Branch
    {
        return Branch::create(array_merge([
            'slug' => 'test-'.Str::lower(Str::random(6)),
            'name_en' => 'Test Branch',
            'name_ar' => 'فرع الاختبار',
            'opens_at' => '00:00:00',
            'closes_at' => '23:59:00',
            'accepts_delivery' => true,
            'delivery_fee' => 3000,
            'minimum_order' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeCategory(array $attributes = []): Category
    {
        return Category::create(array_merge([
            'slug' => 'cat-'.Str::lower(Str::random(6)),
            'name_en' => 'Burgers',
            'name_ar' => 'برغر',
        ], $attributes));
    }

    protected function makeProduct(array $attributes = [], ?Category $category = null): Product
    {
        $category ??= $this->makeCategory();

        return Product::create(array_merge([
            'category_id' => $category->id,
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'slug' => 'product-'.Str::lower(Str::random(6)),
            'name_en' => 'Test Burger',
            'name_ar' => 'برغر تجريبي',
            'base_price' => 10000,
            'prep_time_minutes' => 10,
            'is_active' => true,
            'is_available' => true,
        ], $attributes));
    }

    /**
     * Creates an option group and its options.
     *
     * @param  array<int, array{0: string, 1: float}>  $options  [name, price delta]
     */
    protected function makeOptionGroup(
        Product $product,
        array $options,
        array $attributes = [],
    ): OptionGroup {
        $group = OptionGroup::create(array_merge([
            'product_id' => $product->id,
            'name_en' => 'Size',
            'name_ar' => 'الحجم',
            'kind' => OptionGroupKind::Variant,
            'selection' => OptionSelection::Single,
            'is_required' => true,
            'min_selections' => 1,
            'max_selections' => 1,
        ], $attributes));

        foreach ($options as $index => [$name, $delta]) {
            Option::create([
                'option_group_id' => $group->id,
                'name_en' => $name,
                'name_ar' => $name,
                'price_delta' => $delta,
                'sort_order' => $index,
            ]);
        }

        return $group->load('options');
    }

    protected function makeTable(Branch $branch, array $attributes = []): DiningTable
    {
        return DiningTable::create(array_merge([
            'branch_id' => $branch->id,
            'number' => (string) random_int(1, 9999),
            'capacity' => 4,
        ], $attributes));
    }

    /** Creates a staff user holding a role, scoped to a branch. */
    protected function makeStaff(string $role, ?Branch $branch = null): User
    {
        $user = User::create([
            'name' => Str::title($role),
            'email' => $role.'-'.Str::lower(Str::random(6)).'@viking.test',
            'password' => Hash::make('password'),
            'branch_id' => $branch?->id,
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user->load('roles.permissions');
    }

    protected function makeCustomer(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Customer',
            'email' => 'customer-'.Str::lower(Str::random(6)).'@viking.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ], $attributes));

        $user->assignRole('customer');

        return $user;
    }

    /** A 32-character guest token in the format the API validates. */
    protected function guestToken(): string
    {
        return Str::lower(Str::random(32));
    }
}
