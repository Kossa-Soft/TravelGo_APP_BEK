@extends('admin.layout')
@section('title', $record ? $record->title() : 'Новая запись · '.$type->label())

@push('head')
    @if ($canInspect && in_array($type, [\App\Support\TravelGoType::City, \App\Support\TravelGoType::Place, \App\Support\TravelGoType::Route], true))
        @include('admin.travelgo.map-assets')
    @endif
    <script src="{{ asset('travelgo-content.js') }}" defer></script>
@endpush

@section('content')
    @include('admin.travelgo.nav')
    @include('admin.travelgo.feedback')

    @if ($record)
        <div class="editor-context">
            <span class="status {{ $record->status === \App\Support\ContentStatus::Published ? 'ready' : '' }}">{{ $record->status->label() }}</span>
            @if ($record->publish_at)
                <span>{{ $record->publish_at->format('d.m.Y H:i') }} UTC</span>
            @endif
            @if ($record->status_reason)
                <span>{{ $record->status_reason }}</span>
            @endif
            @if ($actor->hasPermission('travelgo.audit.view') && $actor->canBrowseTravelGo($type))
                <a href="{{ route('admin.travelgo.audit', ['type' => $type->value, 'id' => $record->id]) }}">История изменений</a>
            @endif
        </div>
    @endif

    <div class="editor-stack">
        @if ($canInspect)
            <section class="card">
                <h2>Основные данные</h2>
                <form class="fields" method="post" action="{{ $record ? route('admin.travelgo.update', [$type, $record->publicKey()]) : route('admin.travelgo.store', [$type]) }}">
                    @csrf
                    @if ($record)
                        @method('PATCH')
                    @endif
                    <fieldset class="editor-fieldset" @disabled(! $canEdit)>
                        @include('admin.travelgo.fields')
                    </fieldset>
                    @if ($canEdit)
                        <button type="submit" class="primary">{{ $record ? 'Сохранить' : 'Создать черновик' }}</button>
                    @endif
                </form>
            </section>
        @endif

        @if ($record && $canEdit && in_array($type, [\App\Support\TravelGoType::City, \App\Support\TravelGoType::Place, \App\Support\TravelGoType::Route], true))
            <section class="card">
                <h2>{{ $type === \App\Support\TravelGoType::Place ? 'Загрузить фото' : 'Загрузить обложку' }}</h2>
                <form class="fields" method="post" enctype="multipart/form-data" action="{{ route('admin.travelgo.upload', [$type, $record->publicKey()]) }}">
                    @csrf
                    <label>JPEG, PNG или WebP, до 10 МБ <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
                    <label>Автор или лицензия<input name="credit" maxlength="300"></label>
                    <button type="submit">Загрузить</button>
                </form>
            </section>
        @endif

        @if ($record && $type === \App\Support\TravelGoType::Route && $canInspect)
            @include('admin.travelgo.stops')
        @endif
        @if ($routes->isNotEmpty())
            <section class="card">
                <h2>Маршруты с этим местом</h2>
                <ul class="editor-links">
                    @foreach ($routes as $routeRecord)
                        <li><a href="{{ route('admin.travelgo.edit', [$routeRecord->type, $routeRecord->id]) }}">{{ $routeRecord->title() }}</a> · {{ $routeRecord->status->label() }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($record)
            @include('admin.travelgo.status')
        @endif
    </div>
@endsection
