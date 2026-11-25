<li class="stop-row" data-stop-item>
    <div class="editor-grid">
        <label>Место
            <select name="stops[{{ $index }}][placeId]">
                <option value="">Выберите место</option>
                @foreach ($places as $place)
                    <option value="{{ $place->id }}" @selected($stop?->id === $place->id)>{{ $place->title() }}</option>
                @endforeach
            </select>
        </label>
        <label>Время осмотра, мин<input type="number" min="1" max="1440" name="stops[{{ $index }}][visitMinutes]" value="{{ $stop?->pivot?->visit_minutes }}"></label>
    </div>
    <div class="catalog-actions">
        <button type="button" data-stop-move="-1" aria-label="Переместить выше">↑</button>
        <button type="button" data-stop-move="1" aria-label="Переместить ниже">↓</button>
        <button type="button" data-stop-remove>Убрать</button>
    </div>
    <details class="editor-details">
        <summary>Примечание</summary>
        @php($note = json_decode($stop?->pivot?->note ?? 'null', true))
        <div class="editor-grid">
            @foreach (\App\Support\TravelGoFields::LANGUAGES as $language)
                <label>{{ strtoupper($language) }}<input name="stops[{{ $index }}][note][{{ $language }}]" value="{{ $note[$language] ?? '' }}" maxlength="800"></label>
            @endforeach
        </div>
    </details>
</li>
