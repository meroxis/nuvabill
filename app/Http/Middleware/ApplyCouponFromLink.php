<?php

namespace App\Http\Middleware;

use App\Billing\Cart;
use App\Billing\CouponLookups;
use App\Models\Coupon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A link with ?coupon=CODE (for example in an email or an ad) puts the coupon in the cart, so
 * the discount shows on every price from then on without typing the code. After too many
 * different codes in a minute the page loads without looking the code up, so codes cannot be guessed.
 */
class ApplyCouponFromLink
{
    public function __construct(private Cart $cart) {}

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('coupon');

        if ($request->isMethod('GET') && is_string($code) && $code !== '' && strlen($code) <= 40 && $request->hasSession() && CouponLookups::allow($request->ip(), $code)) {
            $coupon = Coupon::findByCode($code);
            $currency = auth('web')->user()?->currency ?? (string) setting('billing.currency');

            if ($coupon !== null && $coupon->unavailableReason(auth('web')->user(), $currency) === null) {
                $this->cart->setCoupon($coupon->code);
                $request->session()->flash('status', __('Coupon :code is applied to your order.', ['code' => $coupon->code]));
            }
        }

        return $next($request);
    }
}
