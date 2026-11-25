@php
    $contentAdmin = auth('admin')->user();
@endphp
<nav class="catalog-nav" aria-label="Раздел TravelGo">
    <a href="{{ route('admin.travelgo.home') }}" @if(request()->routeIs('admin.travelgo.home')) aria-current="page" @endif>Обзор</a>
    @foreach (\App\Support\TravelGoType::cases() as $section)
        @if ($contentAdmin->canBrowseTravelGo($section))
            <a href="{{ route('admin.travelgo.index', [$section]) }}" @if(isset($type) && $type === $section) aria-current="page" @endif>{{ $section->label() }}</a>
        @endif
    @endforeach
    @if ($contentAdmin->hasPermission('travelgo.publication.view'))
        <a href="{{ route('admin.travelgo.publication') }}" @if(request()->routeIs('admin.travelgo.publication')) aria-current="page" @endif>Публикация</a>
    @endif
    @if ($contentAdmin->hasPermission('travelgo.audit.view'))
        <a href="{{ route('admin.travelgo.audit') }}" @if(request()->routeIs('admin.travelgo.audit')) aria-current="page" @endif>Журнал</a>
    @endif
</nav>
