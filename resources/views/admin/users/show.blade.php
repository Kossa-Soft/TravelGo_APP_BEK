@extends('admin.layout')
@section('title', $user->displayName() ?? 'Пользователь')

@section('content')
    <nav class="catalog-nav" aria-label="Пользователи">
        <a href="{{ route('admin.users.index') }}">Пользователи</a>
        <a href="{{ route('admin.users.journal', ['user' => $user->public_id]) }}">История аккаунта</a>
    </nav>
    @include('admin.travelgo.feedback')
    <div class="editor-stack">
        <section class="card">
            <div class="card-title">
                <div class="user-summary">
                    @if ($user->avatar_id)
                        <img class="user-avatar" src="{{ \App\Services\AvatarImages::url($user->public_id, $user->avatar_id) }}" width="56" height="56" alt="Фото профиля">
                    @endif
                    <div>
                        <h2>{{ $user->phone }}</h2>
                        <p>{{ $user->displayName() ?? 'Без имени' }}</p>
                    </div>
                </div>
                <span class="status{{ $user->isBlocked() ? '' : ' ready' }}">{{ $user->isBlocked() ? 'Заблокирован' : 'Активен' }}</span>
            </div>
            <dl class="integration-diagnostics">
                <div><dt>Язык</dt><dd>{{ strtoupper($user->language) }}</dd></div>
                <div><dt>Регистрация</dt><dd>{{ $user->created_at?->utc()->format('d.m.Y H:i') ?? '—' }}{{ $user->created_at ? ' UTC' : '' }}</dd></div>
                <div><dt>Последний вход</dt><dd>{{ $user->last_login_at?->utc()->format('d.m.Y H:i') ?? 'Нет данных' }}{{ $user->last_login_at ? ' UTC' : '' }}</dd></div>
                <div>
                    <dt>Текущий план</dt><dd>{{ strtoupper($user->effectivePlan()) }}</dd>
                    @if ($user->plan_until)
                        <dd class="catalog-meta">PRO до {{ $user->plan_until->utc()->format('d.m.Y H:i') }} UTC</dd>
                    @elseif ($user->plan === 'pro')
                        <dd class="catalog-meta">PRO без срока</dd>
                    @endif
                </div>
            </dl>
        </section>

        @can('users.plan')
            <section class="card">
                <h2>План</h2>
                <form class="fields integration-fields" method="post" action="{{ route('admin.users.update', $user->public_id) }}">
                    @csrf
                    @method('PATCH')
                    <label>План
                        <select name="plan" required>
                            <option value="free" @selected(old('plan', $user->plan) === 'free')>Free</option>
                            <option value="pro" @selected(old('plan', $user->plan) === 'pro')>PRO</option>
                        </select>
                    </label>
                    <label>Срок PRO, UTC
                        <input name="planUntil" type="datetime-local" value="{{ old('planUntil', $user->plan_until?->utc()->format('Y-m-d\TH:i')) }}">
                        <small>Пустое поле задаёт PRO без срока.</small>
                    </label>
                    <button type="submit" class="primary">Сохранить план</button>
                </form>
            </section>
        @endcan

        @can('users.block')
            <section class="card">
                <h2>Доступ к приложению</h2>
                <form class="fields" method="post" action="{{ route('admin.users.update', $user->public_id) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="blocked" value="{{ $user->isBlocked() ? '0' : '1' }}">
                    @if (! $user->isBlocked())
                        <small>Блокировка завершит все сессии пользователя.</small>
                    @endif
                    <button class="{{ $user->isBlocked() ? '' : 'danger-button' }}" type="submit">{{ $user->isBlocked() ? 'Разблокировать' : 'Заблокировать' }}</button>
                </form>
            </section>
        @endcan

        @can('users.delete')
            <section class="card">
                <h2>Удаление аккаунта</h2>
                <form class="fields" method="post" action="{{ route('admin.users.destroy', $user->public_id) }}">
                    @csrf
                    @method('DELETE')
                    <label class="check-label"><input type="checkbox" name="confirm" value="1" required>Подтверждаю удаление аккаунта и его данных.</label>
                    <label>Пароль администратора <input type="password" name="currentPassword" autocomplete="current-password" required></label>
                    <button type="submit" class="danger-button">Удалить аккаунт</button>
                </form>
            </section>
        @endcan
    </div>
@endsection
