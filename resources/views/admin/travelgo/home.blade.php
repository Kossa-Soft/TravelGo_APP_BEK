@extends('admin.layout')
@section('title', 'TravelGo')

@section('content')
    @include('admin.travelgo.nav')
    @include('admin.travelgo.feedback')
    <div class="catalog-grid">
        @foreach (\App\Support\TravelGoType::cases() as $section)
            @if ($admin->canBrowseTravelGo($section) || $admin->hasPermission($section->permission('create')))
                <section class="card catalog-summary">
                    <h2>{{ $section->label() }}</h2>
                    @if ($admin->canBrowseTravelGo($section))
                        <strong class="catalog-count">{{ $counts->get($section->value, collect())->sum('total') }}</strong>
                        <a class="button-link" href="{{ route('admin.travelgo.index', [$section]) }}">Открыть</a>
                    @else
                        <a class="button-link" href="{{ route('admin.travelgo.create', [$section]) }}">Добавить</a>
                    @endif
                </section>
            @endif
        @endforeach
    </div>
    @if (! $admin->canOpenTravelGo())
        <p class="card">Доступ к разделам назначает суперадминистратор.</p>
    @endif
@endsection
