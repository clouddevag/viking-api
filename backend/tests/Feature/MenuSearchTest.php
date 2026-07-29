<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu search.
 *
 * The interesting case is Arabic. MySQL's full-text index tokenises on
 * whitespace and boolean mode matches a token *prefix*, but Arabic attaches the
 * definite article to the front of the word — so "السفينة" is one token and a
 * customer typing the bare noun "سفينة" would match nothing at all under
 * full-text alone. Arabic is this menu's default locale, so these assertions
 * are the guard on that behaviour.
 */
class MenuSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_a_product_by_its_english_name(): void
    {
        $this->makeProduct(['name_en' => 'Longship Classic', 'name_ar' => 'كلاسيك السفينة']);

        $this->getJson('/api/v1/products/search?q=Longship')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Longship Classic');

        // The same row, serialised into the other locale.
        $this->withHeader('X-Locale', 'ar')
            ->getJson('/api/v1/products/search?q=Longship')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'كلاسيك السفينة');
    }

    public function test_it_finds_an_arabic_noun_carrying_the_definite_article(): void
    {
        $this->makeProduct(['name_en' => 'Longship Classic', 'name_ar' => 'كلاسيك السفينة']);

        // "سفينة" — the bare noun. The stored token is "السفينة".
        $this->withHeader('X-Locale', 'ar')
            ->getJson('/api/v1/products/search?q='.urlencode('سفينة'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_finds_an_arabic_word_written_with_the_article(): void
    {
        $this->makeProduct(['name_en' => 'Shield Wall Smash', 'name_ar' => 'سماش الدرع']);

        $this->withHeader('X-Locale', 'ar')
            ->getJson('/api/v1/products/search?q='.urlencode('الدرع'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_matches_a_word_from_the_middle_of_a_name(): void
    {
        $this->makeProduct(['name_en' => 'Nordic Grilled Chicken', 'name_ar' => 'دجاج الشمال المشوي']);

        $this->getJson('/api/v1/products/search?q=Grilled')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_finds_a_product_by_sku(): void
    {
        $this->makeProduct(['sku' => 'BRG-0042']);

        $this->getJson('/api/v1/products/search?q=BRG-0042')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_returns_nothing_for_a_term_that_matches_no_product(): void
    {
        $this->makeProduct(['name_en' => 'Longship Classic', 'name_ar' => 'كلاسيك السفينة']);

        $this->getJson('/api/v1/products/search?q=sushi')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_single_character_is_rejected_before_it_reaches_the_database(): void
    {
        $this->makeProduct();

        // Guards against a one-letter query scanning the whole catalogue.
        $this->getJson('/api/v1/products/search?q=a')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unavailable_product_is_not_searchable(): void
    {
        $this->makeProduct(['name_en' => 'Longship Classic', 'is_available' => false]);

        $this->getJson('/api/v1/products/search?q=Longship')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_wildcard_is_treated_as_a_literal_not_a_pattern(): void
    {
        $this->makeProduct(['name_en' => 'Longship Classic']);

        // '%' must not match everything — it is escaped before it reaches LIKE.
        $this->getJson('/api/v1/products/search?q='.urlencode('%%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
