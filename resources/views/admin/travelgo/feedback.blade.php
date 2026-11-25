@if (session('status'))
    <div class="notice" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="notice error" role="alert">
        <ul>
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
