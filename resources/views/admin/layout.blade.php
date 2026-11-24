<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <title>@yield('title') · TravelGo</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #172536;
            --muted: #516171;
            --line: #dce4ea;
            --paper: #fff;
            --canvas: #f4f7f9;
            --sidebar: #142535;
            --accent: #eca637;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: var(--canvas); min-height: 100vh; }
        h1, h2, p { margin: 0; }
        h1 { font-size: clamp(2rem, 4vw, 2.85rem); line-height: 1.08; letter-spacing: -.045em; }
        h2 { font-size: 1.25rem; letter-spacing: -.02em; }
        p { line-height: 1.55; }
        .muted { color: var(--muted); }
        .shell { min-height: 100vh; display: grid; grid-template-columns: 15rem minmax(0, 1fr); }
        .rail { background: var(--sidebar); color: #fff; padding: 2rem 1.15rem; display: flex; flex-direction: column; gap: 2.4rem; }
        .brand { display: flex; align-items: center; gap: .65rem; font-weight: 760; font-size: 1.13rem; letter-spacing: -.025em; }
        .mark { display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: .55rem; color: #241b0f; background: var(--accent); }
        .nav-item { display: block; padding: .7rem .8rem; border-radius: .55rem; color: #fff; background: #293e51; text-decoration: none; font-weight: 640; }
        .rail form { margin-top: auto; }
        .rail button { color: #fff; background: transparent; border-color: #617587; }
        .rail button:hover { background: #293e51; }
        .content { width: 100%; max-width: 65rem; padding: clamp(1.25rem, 5vw, 4rem); }
        .content > header { margin-bottom: 1.8rem; }
        .content > header p { margin-top: .65rem; }
        .center { min-height: 100vh; display: grid; place-items: center; padding: 1.25rem; }
        .login { width: min(100%, 28rem); }
        .login .brand { margin-bottom: 1.5rem; }
        .login h1 { font-size: 2rem; margin-bottom: 1rem; }
        .card { background: var(--paper); border: 1px solid var(--line); border-radius: 1.05rem; padding: clamp(1.1rem, 3vw, 2rem); box-shadow: 0 .4rem 1.3rem #17253608; }
        .card-title { display: flex; justify-content: space-between; align-items: flex-start; gap: .7rem; flex-wrap: wrap; }
        .card-title p { font-size: .91rem; margin-top: .25rem; }
        .status { border-radius: 2rem; padding: .3rem .65rem; font-size: .82rem; font-weight: 650; background: #fff1d7; color: #714c13; }
        .status.ready { background: #e3f4ec; color: #215c48; }
        .fields { display: grid; gap: 1.05rem; margin-top: 1.4rem; max-width: 32rem; }
        label { display: grid; gap: .35rem; font-weight: 620; font-size: .9rem; }
        small { display: block; color: var(--muted); font-size: .82rem; font-weight: 450; line-height: 1.45; }
        input { width: 100%; border: 1px solid #b8c5ce; border-radius: .5rem; background: #fff; padding: .65rem .75rem; font: inherit; color: var(--ink); }
        :focus-visible { outline: 3px solid #40688c; outline-offset: 2px; }
        button { width: fit-content; max-width: 100%; border: 1px solid #b8c5ce; border-radius: .55rem; background: #fff; color: var(--ink); padding: .65rem .9rem; font: inherit; font-weight: 650; cursor: pointer; }
        button:hover { background: #edf2f6; }
        .primary { background: var(--accent); border-color: var(--accent); color: #241b0f; }
        .primary:hover { background: #df9827; }
        .check { margin-top: 1.55rem; padding-top: 1.15rem; border-top: 1px solid var(--line); display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1rem; }
        .notice { border: 1px solid #addbc8; color: #1a5140; background: #e3f4ec; border-radius: .55rem; margin-bottom: 1rem; padding: .65rem .8rem; }
        .notice.error { border-color: #efbdbc; color: #7b2524; background: #fff0ef; }
        .notice ul { margin: 0; padding-left: 1.25rem; }
        .meta { margin-top: 1.15rem; font-size: .82rem; color: var(--muted); }
        @media (max-width: 44rem) {
            .shell { display: block; }
            .rail { padding: 1rem; flex-direction: row; flex-wrap: wrap; align-items: center; gap: .7rem 1.1rem; }
            .rail form { margin: 0 0 0 auto; }
            .nav-item { padding: .5rem .65rem; }
        }
    </style>
</head>
<body>
@auth('admin')
    <div class="shell">
        <aside class="rail">
            <div class="brand"><span class="mark" aria-hidden="true">T</span>TravelGo</div>
            <nav aria-label="Панель управления">
                <a class="nav-item" href="{{ route('admin.integrations.show') }}" aria-current="page">Интеграции</a>
            </nav>
            <form method="post" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit">Выйти</button>
            </form>
        </aside>
        <main class="content">@yield('content')</main>
    </div>
@else
    <main class="center">@yield('content')</main>
@endauth
</body>
</html>
