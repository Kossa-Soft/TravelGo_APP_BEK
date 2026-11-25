@extends('admin.layout')
@section('title', 'Настройка аутентификатора')

@section('content')
    <section class="card editor-stack">
        <h2>{{ $admin->email }}</h2>
        <p>Добавьте ключ в приложение-аутентификатор. Выберите код по времени, 6 цифр, 30 секунд.</p>
        <code class="setup-key">{{ $secret }}</code>
        <a class="button-link primary" href="{{ route('admin.staff.edit', $admin) }}">Настроить права</a>
    </section>
@endsection
