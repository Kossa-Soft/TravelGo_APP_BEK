@extends('admin.layout')
@section('title', 'Права администратора')

@section('content')
    @include('admin.travelgo.feedback')
    <section class="card">
        <h2>{{ $admin->email }}</h2>
        @php($selected = (array) old('permissions', $errors->any() ? [] : $admin->permissions))
        <form method="post" class="fields" action="{{ route('admin.staff.update', $admin) }}">
            @csrf
            @method('PATCH')
            <label class="check-label"><input type="checkbox" name="permissions[]" value="travelgo.*" @checked(in_array('travelgo.*', $selected, true))>Полный доступ к TravelGo</label>
            <div class="table-scroll">
                <table class="catalog-table permissions-table">
                    <thead><tr><th scope="col">Раздел</th>
                        @foreach (\App\Support\TravelGoFields::actions() as $label)
                            <th scope="col">{{ $label }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach (\App\Support\TravelGoType::cases() as $type)
                            <tr>
                                <th scope="row">{{ $type->label() }}</th>
                                @foreach (\App\Support\TravelGoFields::actions() as $action => $label)
                                    <td><input type="checkbox" name="permissions[]" value="{{ $type->permission($action) }}" @checked(in_array($type->permission($action), $selected, true)) aria-label="{{ $type->label() }} · {{ $label }}"></td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="editor-grid">
                @foreach (['travelgo.publication.view' => 'Очередь публикации', 'travelgo.audit.view' => 'Журнал TravelGo', 'integrations.alphasms.view' => 'Просмотр AlphaSMS', 'integrations.alphasms.update' => 'Настройка AlphaSMS'] + \App\Http\Controllers\Admin\MobileUserController::PERMISSIONS as $permission => $label)
                    <label class="check-label"><input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, $selected, true))>{{ $label }}</label>
                @endforeach
            </div>
            <label>Твой пароль<input name="currentPassword" type="password" autocomplete="current-password" required></label>
            <div class="catalog-actions">
                <button type="submit" class="primary">Сохранить права</button>
                <a href="{{ route('admin.staff.index') }}">Вернуться к списку</a>
            </div>
        </form>
    </section>
@endsection
