@if ($pages->hasPages())
    <nav class="catalog-pagination" aria-label="Страницы">
        @if ($pages->previousPageUrl())
            <a class="button-link" href="{{ $pages->previousPageUrl() }}">Назад</a>
        @endif
        <span>Страница {{ $pages->currentPage() }} из {{ $pages->lastPage() }}</span>
        @if ($pages->nextPageUrl())
            <a class="button-link" href="{{ $pages->nextPageUrl() }}">Далее</a>
        @endif
    </nav>
@endif
