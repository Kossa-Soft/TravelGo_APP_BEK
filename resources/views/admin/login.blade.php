@extends('admin.layout')
@section('title', 'Вход в админку')

@section('content')
    <section class="card login">
        <div class="brand"><span class="mark" aria-hidden="true">T</span>TravelGo</div>
        <h1>Вход в админку</h1>
        @if ($errors->any())
            <div class="notice error" role="alert">{{ $errors->first() }}</div>
        @endif
        <form class="fields" method="post" action="{{ route('admin.login.store') }}">
            @csrf
            <label>
                Электронная почта
                <input name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
            </label>
            <label>
                Пароль
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            @if ($requiresTotp)
                <label>
                    Код из приложения-аутентификатора
                    <input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                </label>
            @endif
            <button class="primary" type="submit">Войти</button>
        </form>
    </section>
@endsection
