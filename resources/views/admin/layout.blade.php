<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · TravelGo</title>
    <link rel="icon" type="image/png" href="{{ asset('travelgo-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('travelgo.css') }}">
    @auth('admin')
        <script src="{{ asset('travelgo-admin.js') }}" defer></script>
    @endauth
    @stack("head")
</head>
<body>
@auth('admin')
    <div class="shell">
        <aside class="rail">
            <div class="rail-top">
                <img class="brand-logo" src="{{ asset('travelgo-logo.png') }}" width="1000" height="1000" alt="TravelGo">
                <button class="icon-button rail-toggle" type="button" aria-controls="rail-body" aria-expanded="true" aria-label="Свернуть боковую панель" hidden>
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="16" rx="2"/>
                        <path d="M9 4v16"/>
                    </svg>
                </button>
            </div>
            <div class="rail-body" id="rail-body">
                <nav class="rail-nav" aria-label="Панель управления">
                    @if (auth('admin')->user()->canOpenTravelGo())
                        <a class="nav-item" href="{{ route('admin.travelgo.home') }}" @if (request()->routeIs('admin.travelgo.*')) aria-current="page" @endif>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>
                            </svg>
                            <span class="rail-label">TravelGo</span>
                        </a>
                    @endif
                    @can('users.view')
                        <a class="nav-item" href="{{ route('admin.users.index') }}" @if (request()->routeIs('admin.users.*')) aria-current="page" @endif>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <circle cx="9" cy="8" r="3"/><path d="M3 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2m1-8a3 3 0 0 0 0-6m1 8a4 4 0 0 1 4 4v2"/>
                            </svg>
                            <span class="rail-label">Пользователи</span>
                        </a>
                    @endcan
                    @can('staff.manage')
                        <a class="nav-item" href="{{ route('admin.staff.index') }}" @if (request()->routeIs('admin.staff.*')) aria-current="page" @endif>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M4 20v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2m1-6a4 4 0 0 1 3 4v2"/><circle cx="10" cy="7" r="3"/><path d="M16 4a3 3 0 0 1 0 6"/>
                            </svg>
                            <span class="rail-label">Администраторы</span>
                        </a>
                    @endcan
                    @can('integrations.alphasms.view')
                        <a class="nav-item" href="{{ route('admin.integrations.show') }}" @if (request()->routeIs('admin.integrations.*')) aria-current="page" @endif>
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M8 4v5m8-5v5M6 9h12v3a6 6 0 0 1-12 0zm6 9v3"/>
                            </svg>
                            <span class="rail-label">Интеграции</span>
                        </a>
                    @endcan
                </nav>
                <div class="rail-footer">
                    <div class="rail-profile">
                        <span class="profile-icon">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <circle cx="12" cy="8" r="3"/>
                                <path d="M5 20v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/>
                            </svg>
                        </span>
                        <p class="rail-label">
                            <strong>{{ auth('admin')->user()->is_superadmin ? 'Суперадминистратор' : 'Администратор' }}</strong>
                            <span>{{ auth('admin')->user()->email }}</span>
                        </p>
                    </div>
                    <form method="post" action="{{ route('admin.logout') }}">
                        @csrf
                        <button class="nav-item" type="submit">
                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M11 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h6m3-13 5 5-5 5m5-5H9"/>
                            </svg>
                            <span class="rail-label">Выйти</span>
                        </button>
                    </form>
                </div>
            </div>
        </aside>
        <div class="workspace wave-background">
            <header class="topbar">
                <h1>@yield('title')</h1>
            </header>
            <main class="content">@yield('content')</main>
        </div>
    </div>
@else
    <main class="center wave-background">@yield('content')</main>
@endauth
</body>
</html>
