@extends('admin.layout')
@section('title', 'Администраторы')

@section('content')
    @include('admin.travelgo.feedback')
    <div class="editor-stack">
        <section class="card catalog-list">
            <div class="table-scroll">
                <table class="catalog-table">
                    <thead><tr><th>Почта</th><th>Роль</th><th>Доступ</th></tr></thead>
                    <tbody>
                    @foreach ($admins as $account)
                        <tr>
                            <th scope="row">{{ $account->email }}</th>
                            <td>{{ $account->is_superadmin ? 'Суперадминистратор' : 'Администратор' }}</td>
                            <td>
                                @if ($account->is_superadmin)
                                    Полный
                                @else
                                    <a href="{{ route('admin.staff.edit', $account) }}">Настроить права</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @include('admin.travelgo.pagination', ['pages' => $admins])
        </section>
        <section class="card">
            <h2>Новый администратор</h2>
            <form class="fields" method="post" action="{{ route('admin.staff.store') }}">
                @csrf
                <div class="editor-grid">
                    <label>Почта<input type="email" name="email" value="{{ old('email') }}" autocomplete="off" required></label>
                    <label>Твой пароль<input type="password" name="currentPassword" autocomplete="current-password" required></label>
                    <label>Пароль нового администратора<input type="password" name="password" minlength="14" autocomplete="new-password" required></label>
                    <label>Повтори новый пароль<input type="password" name="password_confirmation" minlength="14" autocomplete="new-password" required></label>
                </div>
                <button class="primary" type="submit">Создать администратора</button>
            </form>
        </section>
    </div>
@endsection
