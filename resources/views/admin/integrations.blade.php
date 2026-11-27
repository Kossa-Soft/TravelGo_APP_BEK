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

        <dl class="integration-diagnostics">
            <div>
                <dt>Баланс</dt>
                <dd>{{ $diagnostics->balance_amount !== null ? rtrim(rtrim($diagnostics->balance_amount, '0'), '.') : 'Не проверен' }} {{ $diagnostics->balance_currency }}</dd>
                @if ($diagnostics->balance_checked_at)
                    <dd class="catalog-meta">{{ $diagnostics->balance_checked_at->format('d.m.Y H:i') }} UTC</dd>
                @endif
            </div>
            <div>
                <dt>Отправка SMS</dt>
                <dd>{{ $sendSms ? 'Включена' : 'Выключена' }}</dd>
            </div>
            <div>
                <dt>Последнее SMS, принятое AlphaSMS</dt>
                <dd>{{ $diagnostics->last_sms_accepted_at?->format('d.m.Y H:i') ?? 'Нет данных' }}{{ $diagnostics->last_sms_accepted_at ? ' UTC' : '' }}</dd>
                @if ($diagnostics->last_sms_delivery_status)
                    <dd class="catalog-meta">{{ match ($diagnostics->last_sms_delivery_status) {
                        'ACCEPTED' => 'Принято шлюзом',
                        'DELIVERED' => 'Доставлено',
                        'QUEUED', 'ROUTING' => 'В очереди',
                        'INVALID SOURCE ADDRESS' => 'Неверное имя отправителя',
                        'INVALID DESTINATION ADDRESS' => 'Неверный номер',
                        'NO ROUTE' => 'Отсутствует маршрут доставки',
                        'EXPIRED' => 'Срок доставки истёк',
                        'REJECTED', 'UNDELIVERABLE', 'FILTERED' => 'Доставка не состоялась',
                        default => $diagnostics->last_sms_delivery_status,
                    } }}</dd>
                @endif
            </div>
            <div>
                <dt>Последняя ошибка отправки</dt>
                <dd>{{ $diagnostics->last_sms_error ? \App\Exceptions\AlphaSmsException::label($diagnostics->last_sms_error) : 'Нет данных' }}</dd>
                @if ($diagnostics->last_sms_error_at)
                    <dd class="catalog-meta">{{ $diagnostics->last_sms_error_at->format('d.m.Y H:i') }} UTC</dd>
                @endif
            </div>
        </dl>

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
                    <button type="submit">Обновить баланс и статус</button>
                    <small>Проверка баланса и статуса доставки.</small>
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
