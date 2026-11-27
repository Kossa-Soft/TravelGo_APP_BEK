@extends('admin.layout')
@section('title', 'Журнал пользователей')

@section('content')
    <nav class="catalog-nav" aria-label="Пользователи">
        <a href="{{ route('admin.users.index') }}">Пользователи</a>
        @if (isset($input['user']))
            <a href="{{ route('admin.users.journal') }}">Все действия</a>
        @endif
    </nav>
    <section class="card catalog-list">
        <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th scope="col">Дата, UTC</th><th scope="col">Администратор</th><th scope="col">Аккаунт</th><th scope="col">Действие</th><th scope="col">Детали</th></tr></thead>
                <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td>{{ $event['createdAt']->format('d.m.Y H:i') }}</td>
                        <td>{{ $event['actor'] }}</td>
                        <td>{{ $event['user'] }}</td>
                        <td>{{ ['plan_changed' => 'Изменён план', 'blocked' => 'Блокировка', 'unblocked' => 'Разблокировка', 'deleted' => 'Удаление'][$event['action']] ?? $event['action'] }}</td>
                        <td>
                            @if ($event['action'] === 'plan_changed')
                                {{ strtoupper($event['details']['from']['plan']) }} → {{ strtoupper($event['details']['to']['plan']) }}
                                @if ($event['details']['to']['planUntil'])
                                    <small>До {{ \Illuminate\Support\Carbon::parse($event['details']['to']['planUntil'])->utc()->format('d.m.Y H:i') }} UTC</small>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">Изменений пока нет.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.travelgo.pagination', ['pages' => $events])
    </section>
@endsection
