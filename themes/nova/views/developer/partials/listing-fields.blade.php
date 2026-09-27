{{-- The listing part of the item form: name, type, texts, prices, links, look. --}}
<section class="card" style="display:grid;gap:1rem">
    <h2 style="font-size:1.05rem">{{ $new ? __('2. Listing') : __('Listing') }}</h2>
    <div class="form-grid">
        <x-input name="name" :label="__('Name')" :value="$item->name" required maxlength="120" />
        @if ($new)
            <x-input name="slug" :label="__('Short name')" :value="$item->slug" required maxlength="64" class="mono" :help="__('Must match the slug in your manifest, for example hetzner-cloud.')" />
            <x-select name="type" :label="__('Type')" :options="$types" :value="$item->type" required />
        @endif
        <x-select name="category" :label="__('Category')" :options="array_combine($categories, $categories)" :value="$item->category" :placeholder="__('Choose')" />
    </div>
    <x-input name="summary" :label="__('Short description')" :value="$item->summary" required maxlength="200" :help="__('One sentence, shown on the card.')" />
    <x-textarea name="description" :label="__('Full description')" :value="$item->description" rows="5" />
    <div class="form-grid">
        <x-input name="demo_url" type="url" :label="__('Demo link (optional)')" :value="$item->demo_url" placeholder="https://" />
        <x-input name="docs_url" type="url" :label="__('Help page link')" :value="$item->docs_url" placeholder="https://" />
    </div>
    <div class="field">
        <label for="f-screenshots">{{ __('Screenshots') }}</label>
        <input id="f-screenshots" class="input" type="file" name="screenshots[]" accept="image/png,image/jpeg,image/webp" multiple>
        <p class="help">{{ $item->screenshots ? __('Uploading new screenshots replaces the :count you have.', ['count' => count($item->screenshots)]) : __('Up to 6 images, 1600 pixels wide works best.') }}</p>
    </div>
    <div class="form-grid">
        <x-input name="icon_bg" type="color" :label="__('Icon background')" :value="$item->icon['bg'] ?? '#EEF2F6'" />
        <x-input name="icon_fg" type="color" :label="__('Icon colour')" :value="$item->icon['fg'] ?? '#0F1B2D'" />
    </div>
</section>

<section class="card" style="display:grid;gap:1rem" x-data="{ price: @js(old('price', $item->price ? \App\Support\Money::toDecimal($item->price) : '0')), share: {{ $share }} }">
    <h2 style="font-size:1.05rem">{{ $new ? __('3. Price') : __('Price') }}</h2>
    <div class="form-grid">
        <x-input name="price" type="number" step="0.01" min="0" :label="__('Price for one site (:currency)', ['currency' => setting('billing.currency')])" :value="\App\Support\Money::toDecimal((int) $item->price)" x-model="price" :help="__('0 makes it free.')" />
        <x-input name="update_price" type="number" step="0.01" min="0" :label="__('Yearly updates after year 1')" :value="\App\Support\Money::toDecimal((int) $item->update_price)" />
    </div>
    <div class="dev-split-box" x-show="Number(price) > 0">
        <div class="dev-split"><span :style="'width:' + share + '%'"></span><span :style="'width:' + (100 - share) + '%'"></span></div>
        <div style="display:flex;justify-content:space-between;font-size:.9rem;flex-wrap:wrap;gap:8px">
            <span>{{ __('Buyer pays') }} <b x-text="'$' + Number(price).toFixed(2)"></b></span>
            <span>{{ __('You get') }} <b x-text="'$' + (Number(price) * share / 100).toFixed(2)"></b></span>
            <span>{{ __('Marketplace fee') }} <b x-text="'$' + (Number(price) * (100 - share) / 100).toFixed(2)"></b></span>
        </div>
    </div>
</section>
