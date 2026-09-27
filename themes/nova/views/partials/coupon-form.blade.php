{{-- Enter or remove a coupon. Needs $coupon (a working coupon or null), $couponCode and $couponProblem. --}}
@if ($coupon)
    <div class="coupon-applied">
        <x-icon name="tag" />
        <span><b class="mono">{{ $coupon->code }}</b><br><span class="muted" style="font-size:.82rem">{{ $coupon->describe() }} · {{ $coupon->paymentsLabel() }}</span></span>
        <form method="POST" action="{{ route('cart.coupon') }}">
            @csrf
            <input type="hidden" name="code" value="">
            <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove coupon') }}"><x-icon name="x" /></button>
        </form>
    </div>
@else
    <form method="POST" action="{{ route('cart.coupon') }}" class="coupon-form">
        @csrf
        <label for="coupon-code" class="label">{{ __('Coupon code') }}</label>
        <div style="display:flex;gap:8px">
            <input class="input mono" id="coupon-code" name="code" value="{{ old('code', $couponCode ?? '') }}" maxlength="40" autocomplete="off" spellcheck="false" @error('code') aria-invalid="true" aria-describedby="coupon-error" @enderror>
            <button class="btn" type="submit">{{ __('Apply') }}</button>
        </div>
        @error('code')<p class="error" id="coupon-error">{{ $message }}</p>@enderror
        @if (! $errors->has('code') && ($couponProblem ?? null))<p class="error">{{ $couponProblem }}</p>@endif
    </form>
@endif
