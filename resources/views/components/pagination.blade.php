@if ($paginator->hasPages())
    <div class="pagination">
        <span>{{ __('Showing :first–:last of :total', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        <nav aria-label="{{ __('Pages') }}">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm" aria-disabled="true">{{ __('Previous') }}</span>
            @else
                <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
            @endif
            @if ($paginator->hasMorePages())
                <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
            @else
                <span class="btn btn-sm" aria-disabled="true">{{ __('Next') }}</span>
            @endif
        </nav>
    </div>
@endif
