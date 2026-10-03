<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plan cards and product page in the store: feature lists in every language, and prices that
 * show every charge.
 */
class StorePlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_lists_keep_every_language_intact(): void
    {
        $product = Product::factory()->priced(899)->create(['description' => "✓ 10 GB SSD\nاستضافة مجانية\nFree SSL™\n€5 credit"]);
        $group = $product->group;

        foreach ([route('store.index'), route('store.group', $group), route('store.product', [$group, $product])] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertSee('✓ 10 GB SSD', false)
                ->assertSee('استضافة مجانية', false)
                ->assertSee('Free SSL™', false)
                ->assertSee('€5 credit', false)
                ->assertDontSee("\u{FFFD}", false);

            $this->assertTrue(mb_check_encoding((string) $response->getContent(), 'UTF-8'), $url);
        }
    }

    public function test_a_plan_with_no_monthly_price_but_a_setup_fee_shows_the_fee(): void
    {
        $product = Product::factory()->priced(0, setupFee: 1000)->create(['name' => 'Starter']);
        $setup = __('+ :fee setup', ['fee' => money(1000, 'USD')]);

        $this->get(route('store.index'))->assertOk()->assertSee($setup);
        $this->get(route('store.group', $product->group))->assertOk()->assertSee($setup);
    }

    public function test_a_plan_that_is_really_free_shows_no_setup_fee(): void
    {
        $product = Product::factory()->priced(0)->create(['name' => 'Starter']);

        $this->get(route('store.group', $product->group))->assertOk()->assertSee('Free')->assertDontSee('setup');
    }
}
