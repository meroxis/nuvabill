<?php

namespace Tests\Feature\Billing;

use App\Billing\CouponLookups;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coupon links and the order form's price checks cannot be used to guess coupon codes.
 */
class CouponLookupLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Coupon::factory()->create(['code' => 'SECRET']);
    }

    public function test_coupon_links_stop_looking_up_codes_after_the_limit(): void
    {
        for ($i = 0; $i < CouponLookups::PER_MINUTE; $i++) {
            $this->get(route('store.index', ['coupon' => 'NOPE'.$i]))->assertOk();
        }

        $this->get(route('store.index', ['coupon' => 'secret']))->assertOk()->assertDontSee('Coupon SECRET is applied');
        $this->assertNull(session('cart.coupon'));
    }

    public function test_price_checks_and_links_share_one_limit(): void
    {
        $product = Product::factory()->priced(999)->create(['requires_domain' => false]);

        for ($i = 0; $i < CouponLookups::PER_MINUTE; $i++) {
            $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'coupon' => 'NOPE'.$i])
                ->assertOk()
                ->assertJsonPath('coupon', null);
        }

        $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'coupon' => 'SECRET'])
            ->assertOk()
            ->assertJsonPath('coupon', null)
            ->assertJsonPath('coupon_problem', 'Too many coupon codes were tried. Wait a minute, then try again.');

        $this->get(route('store.index', ['coupon' => 'secret']))->assertOk()->assertDontSee('Coupon SECRET is applied');
    }

    public function test_checking_the_same_code_again_does_not_count(): void
    {
        $product = Product::factory()->priced(999)->create(['requires_domain' => false]);

        for ($i = 0; $i < CouponLookups::PER_MINUTE + 5; $i++) {
            $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'coupon' => 'secret'])
                ->assertOk()
                ->assertJsonPath('coupon.code', 'SECRET');
        }

        $this->get(route('store.index', ['coupon' => 'secret']))->assertOk()->assertSee('Coupon SECRET is applied');
    }
}
