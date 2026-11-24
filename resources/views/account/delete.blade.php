<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Видалення акаунта · TravelGo</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7f9; color: #172536; font: 1rem/1.55 system-ui, sans-serif; }
        main { width: min(calc(100% - 2rem), 32rem); margin: clamp(1rem, 8vh, 5rem) auto; background: white; border: 1px solid #dce4ea; border-radius: 1rem; padding: clamp(1.2rem, 5vw, 2.2rem); }
        .brand { font-weight: 750; color: #293e51; }
        h1 { font-size: 1.8rem; line-height: 1.18; margin: .7rem 0; }
        form { display: grid; gap: .9rem; }
        label { display: grid; gap: .3rem; font-weight: 600; }
        input:not([type="checkbox"]) { width: 100%; padding: .6rem; border: 1px solid #a5b5c3; border-radius: .4rem; font: inherit; }
        .confirm { display: flex; align-items: start; gap: .55rem; }
        input[type="checkbox"] { margin-top: .45rem; }
        button { width: fit-content; border: 0; border-radius: .45rem; padding: .65rem 1rem; color: white; background: #a52f2f; font: inherit; font-weight: 650; cursor: pointer; }
        :focus-visible { outline: 3px solid #40688c; outline-offset: 2px; }
        .error { color: #8b2727; }
    </style>
</head>
<body>
<main>
    <span class="brand">TravelGo</span>
    <h1>Видалення акаунта</h1>

    @if (session('account_deleted'))
        <p role="status">Акаунт видалено.</p>
    @else
        <p>Після підтвердження ваш профіль і фото буде видалено, а всі сеанси TravelGo буде завершено.</p>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif

        <form method="post" action="{{ route('account.delete.store') }}">
            @csrf
            <label>
                Номер телефону
                <input name="phone" type="tel" autocomplete="username" placeholder="+380…" required>
            </label>
            <label>
                Пароль
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <label class="confirm">
                <input name="confirm" type="checkbox" value="1" required>
                Підтверджую видалення акаунта
            </label>
            <button type="submit">Видалити акаунт</button>
        </form>
    @endif
</main>
</body>
</html>
