@extends('admin.layout')
@section('title', 'Журнал изменений')

@section('content')
    @include('admin.travelgo.nav')
    @include('admin.travelgo.feedback')
    <section class="card catalog-list">
        <form class="catalog-filters" method="get" action="{{ route('admin.travelgo.audit') }}">
            <label>Раздел
                <select name="type">
                    <option value="">Все доступные</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected(($input['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit">Показать</button>
            @if (isset($input['id']))
                <a href="{{ route('admin.travelgo.audit') }}">Показать весь журнал</a>
            @endif
        </form>
        <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th>Дата (UTC)</th><th>Кто</th><th>Действие</th><th>Детали</th></tr></thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr>
                            <td>{{ $event->created_at }}</td>
                            <td>{{ $event->actor ?? 'Сервер' }}</td>
                            <td>{{ ['create' => 'Создание', 'edit' => 'Редактирование', 'status' => 'Смена статуса', 'stops' => 'Остановки', 'delete' => 'Удаление', 'duplicate' => 'Копирование', 'recalculate' => 'Пересчёт', 'publication_failed' => 'Ошибка отложенной публикации', 'staff_created' => 'Создание администратора', 'staff_permissions' => 'Изменение прав'][$event->action] ?? $event->action }}</td>
                            <td>
                                @if (isset($event->details['fields']))
                                    {{ implode(', ', $event->details['fields']) }}
                                @elseif (isset($event->details['from'], $event->details['to']))
                                    {{ \App\Support\ContentStatus::from($event->details['from'])->label() }}
                                    → {{ \App\Support\ContentStatus::from($event->details['to'])->label() }}
                                    {{ $event->details['reason'] ?? '' }}
                                @elseif (isset($event->details['problems']))
                                    {{ implode(' ', $event->details['problems']) }}
                                @elseif (isset($event->details['account']))
                                    {{ $event->details['account'] }}
                                @elseif (isset($event->details['count']))
                                    {{ $event->details['count'] }} остановок
                                @else
                                    {{ $event->content_id ?? '—' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Изменений пока нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.travelgo.pagination', ['pages' => $events])
    </section>
@endsection
