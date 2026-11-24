@extends('admin.layout')
@section('title', 'Интеграции')

@section('content')
    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="notice error" role="alert">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="card" aria-labelledby="alphasms-title">
        <div class="card-title">
            <div>
                <h2 id="alphasms-title">AlphaSMS</h2>
                <p class="muted">SMS для регистрации и восстановления доступа.</p>
            </div>
            <span class="status{{ $hasKey ? ' ready' : '' }}">{{ $hasKey ? 'Ключ сохранён' : 'Требуется ключ' }}</span>
        </div>

        @if ($canUpdate)
            <form class="fields integration-fields" method="post" action="{{ route('admin.integrations.update') }}" autocomplete="off">
                @csrf
                @method('PATCH')

                <label>
                    Имя отправителя
                    <input name="sender" type="text" value="{{ old('sender', $sender) }}" maxlength="11" spellcheck="false" required>
                    <small>Укажите имя, одобренное AlphaSMS, например Site-Code для тестов.</small>
                </label>

                <label>
                    {{ $hasKey ? 'Новый API-ключ' : 'API-ключ' }}
                    <input name="api_key" type="password" autocomplete="new-password" maxlength="512" @required(! $hasKey)>
                    @if ($hasKey)
                        <small>Оставьте поле пустым, чтобы сохранить текущий ключ.</small>
                    @endif
                </label>

                <label>
                    Пароль администратора
                    <input name="admin_password" type="password" autocomplete="current-password" required>
                    <small>Подтверждает право изменить настройки.</small>
                </label>

                <div class="form-actions">
                    <button type="submit" class="primary">Сохранить</button>
                </div>
            </form>

            @if ($hasKey)
                <form class="check" method="post" action="{{ route('admin.integrations.check') }}">
                    @csrf
                    <button type="submit">Проверить подключение</button>
                    <small>Проверка запрашивает баланс в AlphaSMS и не отправляет SMS.</small>
                </form>
            @endif
        @else
            <p class="meta">Имя отправителя: {{ $sender ?? 'не настроено' }}</p>
        @endif

        @if ($updatedAt)
            <p class="meta">Обновлено <time datetime="{{ $updatedAt->toIso8601String() }}">{{ $updatedAt->format('d.m.Y H:i T') }}</time></p>
        @endif
    </section>
@endsection
