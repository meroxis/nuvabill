@props(['hideErrors' => false])
{{-- Success, warning and error messages after a form submit. --}}
@if (session('status'))
    <div class="flash" role="status">
        <x-icon name="check" style="width:18px;height:18px;flex:none;color:var(--nb-good)" />
        <span>{{ session('status') }}</span>
    </div>
@endif
@if (session('warning'))
    <div class="flash" data-tone="warn" role="alert">
        <x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-warn)" />
        <span>{{ session('warning') }}</span>
    </div>
@endif
@if (session('error'))
    <div class="flash" data-tone="crit" role="alert">
        <x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-crit)" />
        <span>{{ session('error') }}</span>
    </div>
@endif
@if ($errors->any() && ! $hideErrors)
    <div class="flash" data-tone="crit" role="alert">
        <x-icon name="alert" style="width:18px;height:18px;flex:none;color:var(--nb-crit)" />
        <span>{{ trans_choice('Please fix the highlighted field.|Please fix the :count highlighted fields.', $errors->count(), ['count' => $errors->count()]) }}</span>
    </div>
@endif
