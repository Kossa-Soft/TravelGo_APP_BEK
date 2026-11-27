@extends('admin.layout')
@section('title', 'Пользователи')

@section('content')
    @include('admin.travelgo.feedback')
    <section class="card catalog-list">
        <div class="catalog-toolbar">
            <form class="catalog-filters" method="get" action="{{ route('admin.users.index') }}">
                <label>Имя или телефон<input name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="100"></label>
                <label>Дата регистрации
                    <select name="sort">
                        <option value="newest">Сначала новые</option>
                        <option value="oldest" @selected(($filters['sort'] ?? null) === 'oldest')>Сначала ранние</option>
                    </select>
                </label>
                <button type="submit">Найти</button>
                @if (filled($filters['q'] ?? null))
                    <a href="{{ route('admin.users.index') }}">Сбросить</a>
                @endif
            </form>
            <a class="button-link" href="{{ route('admin.users.journal') }}">Журнал действий</a>
        </div>
        <div class="table-scroll">
            <table class="catalog-table">
                <thead><tr><th scope="col">Имя</th><th scope="col">Телефон</th><th scope="col">План</th><th scope="col">Статус</th><th scope="col">Регистрация, UTC</th></tr></thead>
                <tbody>
                @forelse ($users as $user)
                    <tr>
                        <th scope="row"><a href="{{ route('admin.users.show', $user->public_id) }}">{{ $user->displayName() ?? 'Без имени' }}</a></th>
                        <td>{{ $user->phone }}</td>
                        <td>{{ strtoupper($user->effectivePlan()) }}</td>
                        <td><span class="status{{ $user->isBlocked() ? '' : ' ready' }}">{{ $user->isBlocked() ? 'Заблокирован' : 'Активен' }}</span></td>
                        <td>{{ $user->created_at?->utc()->format('d.m.Y H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Пользователи не найдены.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.travelgo.pagination', ['pages' => $users])
    </section>
@endsection
