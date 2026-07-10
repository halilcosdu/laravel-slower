@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        <span class="page-info">
            {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}
        </span>
        <span class="page-links">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm" aria-disabled="true" style="opacity: .5;">‹ Previous</span>
            @else
                <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>
            @else
                <span class="btn btn-sm" aria-disabled="true" style="opacity: .5;">Next ›</span>
            @endif
        </span>
    </nav>
@endif
