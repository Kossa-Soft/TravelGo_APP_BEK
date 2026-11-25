@extends('admin.layout')
@section('title', 'Публикация')

@section('content')
    @include('admin.travelgo.nav')
    @include('admin.travelgo.feedback')
    <section class="card catalog-list">
        <form class="catalog-filters" method="get" action="{{ route('admin.travelgo.publication') }}">
            <label>Статус
                <select name="status">
                    <option value="">На проверке, запланировано и скрыто</option>
                    @foreach (\App\Support\ContentStatus::cases() as $option)
                        <option value="{{ $option->value }}" @selected(($filters['status'] ?? '') === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">Показать</button>
        </form>
        <form class="fields" method="post" action="{{ route('admin.travelgo.publish') }}">
            @csrf
            <div class="table-scroll">
                <table class="catalog-table">
                    <thead><tr><th scope="col">Выбрать</th><th scope="col">Название</th><th scope="col">Раздел</th><th scope="col">Статус</th><th scope="col">Публикация (UTC)</th></tr></thead>
                    <tbody>
                        @forelse ($records as $record)
                            <tr>
                                <td>
                                    @if (in_array(\App\Support\ContentStatus::Published, $record->status->editorialTransitions(), true) && $admin->hasPermission($record->type->permission('publish')))
                                        <input type="checkbox" name="selected[]" value="{{ $record->type->value }}:{{ $record->publicKey() }}" aria-label="Выбрать {{ $record->title() }}">
                                    @endif
                                </td>
                                <th scope="row"><a href="{{ route('admin.travelgo.edit', [$record->type, $record->publicKey()]) }}">{{ $record->title() }}</a></th>
                                <td>{{ $record->type->label() }}</td>
                                <td>{{ $record->status->label() }}
                                    @if ($record->status_reason === 'publish_check_failed')
                                        <small>Проверка отложенной публикации не прошла</small>
                                    @endif
                                </td>
                                <td>{{ $record->publish_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Нет записей с этим статусом.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($records->isNotEmpty())
                <button class="primary" type="submit">Опубликовать выбранные</button>
            @endif
        </form>
        @include('admin.travelgo.pagination', ['pages' => $records])
    </section>
@endsection
