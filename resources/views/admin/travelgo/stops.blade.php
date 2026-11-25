<section class="card">
    <h2>Остановки и линия маршрута</h2>
    @if (isset($viewModel['path']))
        <p class="catalog-meta">{{ $viewModel['distanceKm'] }} км · {{ $viewModel['durationMinutes'] }} мин · {{ $stops->count() }} остановок</p>
        @if (($viewModel['pathSource'] ?? null) === 'estimated')
            <p class="catalog-meta">Линия и расстояние рассчитаны ориентировочно по точкам.</p>
        @endif
    @endif
    @if ($canEdit)
        <form class="fields" method="post" action="{{ route('admin.travelgo.stops', $record->id) }}" data-stops-form>
            @csrf
            @method('PUT')
            <ol class="stop-list" data-stop-list>
                @foreach ($stops as $stop)
                    @include('admin.travelgo.stop-row', ['stop' => $stop, 'index' => $loop->index])
                @endforeach
                @if ($stops->isEmpty())
                    @include('admin.travelgo.stop-row', ['stop' => null, 'index' => 0])
                @endif
            </ol>
            <template data-stop-template>
                @include('admin.travelgo.stop-row', ['stop' => null, 'index' => 'new'])
            </template>
            <div class="catalog-actions">
                <button type="button" data-add-stop>Добавить остановку</button>
                <button type="submit" class="primary">Сохранить остановки</button>
            </div>
        </form>
        <form class="catalog-actions" method="post" action="{{ route('admin.travelgo.recalculate', $record->id) }}">
            @csrf
            <button type="submit">Пересчитать линию</button>
        </form>
    @else
        <ol class="editor-links">
            @foreach ($stops as $stop)
                <li>{{ $stop->title() }} · {{ $stop->pivot->visit_minutes }} мин</li>
            @endforeach
        </ol>
    @endif
</section>
