<form method="GET" action="{{ route('kb.index') }}" class="help-search" role="search">
    <input class="input" type="search" name="q" value="{{ $query ?? '' }}" maxlength="200" placeholder="{{ __('Search, for example “email password”') }}" aria-label="{{ __('Search the knowledge base') }}">
    <button class="btn btn-primary" type="submit"><x-icon name="search" />{{ __('Search') }}</button>
</form>
