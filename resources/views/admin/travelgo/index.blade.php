@extends('admin.layout')
@section('title', $type->label())

@push('head')
    @if ($type === \App\Support\TravelGoType::Place)
        @include('admin.travelgo.map-assets')
        <script src="{{ asset('travelgo-content.js') }}" defer></script>
    @endif
@endpush

@section('content')
    @include('admin.travelgo.nav')
    @include('admin.travelgo.feedback')
    <section class="card catalog-list">
        <div class="catalog-toolbar">
            <form class="catalog-filters" method="get" action="{{ route('admin.travelgo.index', [$type]) }}" aria-label="Фильтры">
                <label>Название или код<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120"></label>
                <label>Статус
                    <select name="status">
                        <option value="">Все</option>
                        @foreach (\App\Support\ContentStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($type === \App\Support\TravelGoType::Place)
                    <label>Категория
                        <select name="category">
                            <option value="">Все</option>
                            @foreach (\App\Support\TravelGoFields::CATEGORIES as $category)
                                <option @selected(($filters['category'] ?? null) === $category)>{{ $category }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @foreach (['countryCode', 'cityId'] as $key)
                    @isset($filters[$key])
                        <input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">
                    @endisset
                @endforeach
                <button type="submit">Найти</button>
                @if (array_filter($filters))
                    <a href="{{ route('admin.travelgo.index', [$type]) }}">Сбросить</a>
                @endif
            </form>
            @if (auth('admin')->user()->hasPermission($type->permission('create')))
                <a class="button-link primary" href="{{ route('admin.travelgo.create', [$type]) }}">Добавить</a>
            @endif
        </div>
        @if ($type === \App\Support\TravelGoType::Place && $mapPoints !== [])
            <div class="content-map content-list-map" data-content-map data-places="{{ \Illuminate\Support\Js::encode($mapPoints) }}" aria-label="Места на карте" hidden></div>
        @endif
        <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th>Название</th><th>Расположение</th><th>Статус</th><th>Обновлено</th></tr></thead>
                <tbody>
                @forelse ($records as $record)
                    <tr>
                        <th scope="row"><a href="{{ route('admin.travelgo.edit', [$type, $record->publicKey()]) }}">{{ $record->title() }}</a></th>
                        <td>
                            @if ($record->parent)
                                {{ $record->parent->title() }}
                            @else
                                {{ $record->country_code ?? '—' }}
                            @endif
                        </td>
                        <td><span class="status {{ $record->status === \App\Support\ContentStatus::Published ? 'ready' : '' }}">{{ $record->status->label() }}</span></td>
                        <td>{{ $record->updated_at->format('d.m.Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">Записи не найдены.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.travelgo.pagination', ['pages' => $records])
    </section>
@endsection
