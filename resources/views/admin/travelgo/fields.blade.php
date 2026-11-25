@php
    use App\Support\TravelGoFields;
    use App\Support\TravelGoType;

    $isPoint = in_array($type, [TravelGoType::City, TravelGoType::Place], true);
    $isRoute = $type === TravelGoType::Route;
    $isPlace = $type === TravelGoType::Place;
    $hasCover = $type === TravelGoType::City || $isRoute;
@endphp
<div class="editor-grid">
    @if ($type === TravelGoType::Country)
        @if ($record)
            <label>Код страны<input value="{{ $record->country_code }}" readonly></label>
        @else
            <label>Код страны<input name="code" value="{{ old('code') }}" maxlength="2" pattern="[A-Z]{2}" placeholder="UA" required></label>
        @endif
    @endif

    @if (in_array($type, [TravelGoType::Region, TravelGoType::City], true))
        <label>Страна
            <select name="countryCode" data-country required>
                <option value="">Выберите страну</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->country_code }}" @selected(old('countryCode', $viewModel['countryCode'] ?? null) === $parent->country_code)>{{ $parent->title() }} · {{ $parent->country_code }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @if ($type === TravelGoType::City)
        <label>Регион
            <select name="regionId" data-region>
                <option value="">Без региона</option>
                @foreach ($regions as $region)
                    <option data-country-code="{{ $region->parent->country_code }}" value="{{ $region->id }}" @selected(old('regionId', $viewModel['regionId'] ?? null) === $region->id)>{{ $region->title() }} · {{ $region->parent->country_code }}</option>
                @endforeach
            </select>
        </label>
        <label>Часовой пояс<input name="timeZone" value="{{ old('timeZone', $viewModel['timeZone'] ?? null) }}" placeholder="Europe/Kyiv" list="travelgo-timezones"></label>
        @if ($canEdit && filled(config('services.travelgo.geonames_username')))
            <div class="catalog-actions">
                <button type="button" data-timezone-url="{{ route('admin.travelgo.api.geo.timezone') }}">Определить часовой пояс</button>
            </div>
        @endif
        <datalist id="travelgo-timezones">
            @foreach (\DateTimeZone::listIdentifiers() as $timezone)
                <option value="{{ $timezone }}"></option>
            @endforeach
        </datalist>
        <label>Радиус города, км<input name="radiusKm" type="number" step="0.1" min="0.1" max="200" value="{{ old('radiusKm', $viewModel['radiusKm'] ?? 15) }}"></label>
    @endif
    @if ($isPlace || $isRoute)
        <label>Город
            <select name="cityId" required>
                <option value="">Выберите город</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected(old('cityId', $viewModel['cityId'] ?? null) === $parent->id)>{{ $parent->title() }} · {{ $parent->parent->country_code }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @if ($isPoint)
        <label>Широта<input name="latitude" type="number" step="any" min="-90" max="90" value="{{ old('latitude', $viewModel['latitude'] ?? null) }}"></label>
        <label>Долгота<input name="longitude" type="number" step="any" min="-180" max="180" value="{{ old('longitude', $viewModel['longitude'] ?? null) }}"></label>
    @endif
    @if ($hasCover)
        <label>Ссылка на обложку<input name="coverImage" type="url" maxlength="2048" value="{{ old('coverImage', $viewModel['coverImage'] ?? null) }}"></label>
        <label>Автор или лицензия обложки<input name="coverCredit" maxlength="300" value="{{ old('coverCredit', $viewModel['coverCredit'] ?? null) }}"></label>
    @endif
    @if ($isPlace)
        <label>Категория
            <select name="category">
                <option value="">Выберите категорию</option>
                @foreach (TravelGoFields::CATEGORIES as $category)
                    <option @selected(old('category', $viewModel['category'] ?? null) === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </label>
        <label>Адрес<input name="address" maxlength="500" value="{{ old('address', $viewModel['address'] ?? null) }}"></label>
        <label>Официальный сайт<input name="website" type="url" maxlength="2048" value="{{ old('website', $viewModel['website'] ?? null) }}"></label>
        <label>Статья Википедии<input name="wiki" maxlength="200" value="{{ old('wiki', $viewModel['wiki'] ?? null) }}" placeholder="uk:Лувр"></label>
        <label>Время осмотра, мин<input name="visitMinutes" type="number" min="1" max="1440" value="{{ old('visitMinutes', $viewModel['visitMinutes'] ?? 20) }}"></label>
        <label>Приоритет места, 1–5<input name="priority" type="number" min="1" max="5" value="{{ old('priority', $viewModel['priority'] ?? 3) }}"></label>
    @endif
    @if ($isRoute)
        <label>Передвижение<select name="transport"><option value="walk">Пешком</option></select></label>
        <label>Приоритет маршрута<input name="sortPriority" type="number" min="0" max="1000000" value="{{ old('sortPriority', $viewModel['sortPriority'] ?? 0) }}"></label>
        <label>Время вручную, мин<input name="durationMinutes" type="number" min="1" max="15000" value="{{ old('durationMinutes', $viewModel['durationMinutes'] ?? null) }}"></label>
        <label>Метка
            <select name="badge">
                <option value="">Без метки</option>
                @foreach (['editorsChoice' => 'Выбор редакции', 'popular' => 'Популярный', 'new' => 'Новый', 'pro' => 'PRO'] as $badge => $label)
                    <option value="{{ $badge }}" @selected(old('badge', $viewModel['badge'] ?? null) === $badge)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <input type="hidden" name="isOfficial" value="0">
        <label class="check-label"><input type="checkbox" name="isOfficial" value="1" @checked(old('isOfficial', $viewModel['isOfficial'] ?? true))>Маршрут від TravelGo</label>
        <input type="hidden" name="isLoop" value="0">
        <label class="check-label"><input type="checkbox" name="isLoop" value="1" @checked(old('isLoop', $viewModel['isLoop'] ?? false))>Кольцевой маршрут</label>
    @endif
    <label>Порядок в списке<input name="sortOrder" type="number" min="0" max="1000000" value="{{ old('sortOrder', $viewModel['sortOrder'] ?? 0) }}"></label>
</div>

@if ($isPoint && $canEdit && filled(config('services.travelgo.geocoder_url')))
    <div class="editor-details" data-geo-search="{{ route('admin.travelgo.api.geo.search') }}">
        <div class="catalog-actions">
            <label>Поиск по адресу<input type="search" minlength="3" maxlength="160" data-address-query></label>
            <button type="button" data-address-search>Найти</button>
        </div>
        <div class="catalog-actions" data-address-results></div>
    </div>
@endif
    <p class="catalog-meta" data-geo-message hidden role="status"></p>
@if ($hasCover && filled($viewModel['coverImage'] ?? null))
    <img class="content-photo" src="{{ $viewModel['coverImage'] }}" alt="Обложка" loading="lazy" referrerpolicy="no-referrer">
@endif
@if ($isPoint || $isRoute)
    <div class="content-map" data-content-map data-editable="{{ $isPoint && $canEdit ? '1' : '0' }}"
        data-lat="{{ $viewModel['latitude'] ?? '' }}" data-lng="{{ $viewModel['longitude'] ?? '' }}"
        data-path="{{ \Illuminate\Support\Js::encode($viewModel['path'] ?? null) }}"
        aria-label="{{ $isRoute ? 'Предпросмотр маршрута' : 'Местоположение на карте' }}" hidden></div>
    @if ($isPoint && $canEdit)
        <small>Клик по карте подставляет координаты. Широту и долготу также можно ввести вручную.</small>
    @endif
@endif
@if ($isRoute)
    @foreach (['start' => 'Старт', 'end' => 'Финиш'] as $point => $label)
        <details class="editor-details">
            <summary>{{ $label }}</summary>
            <div class="editor-grid">
                <label>Место
                    <select name="{{ $point }}[placeId]">
                        <option value="">По остановкам или координатам</option>
                        @foreach ($places as $place)
                            <option value="{{ $place->id }}" @selected(old($point.'.placeId', $viewModel[$point]['placeId'] ?? null) === $place->id)>{{ $place->title() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Широта<input name="{{ $point }}[latitude]" type="number" step="any" min="-90" max="90" value="{{ old($point.'.latitude', $viewModel[$point]['latitude'] ?? null) }}"></label>
                <label>Долгота<input name="{{ $point }}[longitude]" type="number" step="any" min="-180" max="180" value="{{ old($point.'.longitude', $viewModel[$point]['longitude'] ?? null) }}"></label>
                @foreach (TravelGoFields::LANGUAGES as $language)
                    <label>Название {{ strtoupper($language) }}<input name="{{ $point }}[name][{{ $language }}]" maxlength="160" value="{{ old($point.'.name.'.$language, $viewModel[$point]['name'][$language] ?? null) }}"></label>
                @endforeach
            </div>
        </details>
    @endforeach
@endif
@if ($isPlace)
    @php($hours = $viewModel['hours'] ?? null)
    <details class="editor-details">
        <summary>Часы работы</summary>
        <div class="editor-grid">
            <label class="check-label"><input type="checkbox" name="hoursMode" value="always" @checked(old('hoursMode', $hours) === 'always')>Круглосуточно</label>
            <label>Открывается<input type="time" name="hours[open]" value="{{ old('hours.open', $hours['open'] ?? null) }}"></label>
            <label>Закрывается<input type="time" name="hours[close]" value="{{ old('hours.close', $hours['close'] ?? null) }}"></label>
        </div>
        <div class="weekday-options" aria-label="Выходные">
            @foreach (['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'] as $day => $name)
                <label class="check-label"><input type="checkbox" name="hours[closedOn][]" value="{{ $day }}" @checked(in_array($day, (array) old('hours.closedOn', $hours['closedOn'] ?? [])))>{{ $name }}</label>
            @endforeach
        </div>
    </details>
    <details class="editor-details">
        <summary>Фото и авторство</summary>
        @php($photos = array_values(array_filter((array) old('images', $viewModel['images'] ?? []), 'is_array')))
        @foreach (array_slice([...$photos, []], 0, 12) as $index => $photo)
            @if (is_string($photo['url'] ?? null) && filled($photo['url']))
                <img class="content-photo" src="{{ $photo['url'] }}" alt="Фото {{ $index + 1 }}" loading="lazy" referrerpolicy="no-referrer">
            @endif
            <div class="editor-grid">
                <label>Ссылка на фото {{ $index + 1 }}<input type="url" name="images[{{ $index }}][url]" maxlength="2048" value="{{ is_string($photo['url'] ?? null) ? $photo['url'] : '' }}"></label>
                <label>Автор или лицензия<input name="images[{{ $index }}][credit]" maxlength="300" value="{{ is_string($photo['credit'] ?? null) ? $photo['credit'] : '' }}"></label>
            </div>
        @endforeach
    </details>
@endif

@foreach (TravelGoFields::LANGUAGES as $language)
    <details class="editor-details" @if($language === 'uk') open @endif>
        <summary>{{ ['uk' => 'Українська', 'en' => 'English', 'de' => 'Deutsch', 'es' => 'Español', 'fr' => 'Français'][$language] }}</summary>
        <div class="fields">
            @foreach (TravelGoFields::textFields($type) as $key => $label)
                <label>{{ $label }}<input name="{{ $key }}[{{ $language }}]" value="{{ old($key.'.'.$language, $viewModel[$key][$language] ?? null) }}" maxlength="{{ in_array($key, ['name', 'title'], true) ? 160 : 800 }}"></label>
            @endforeach
            @foreach (TravelGoFields::paragraphFields($type) as $key => $label)
                <label>{{ $label }}
                    <textarea name="paragraphs[{{ $key }}][{{ $language }}]" rows="3" maxlength="24000">{{ old('paragraphs.'.$key.'.'.$language, collect($viewModel[$key] ?? [])->pluck($language)->implode("\n\n")) }}</textarea>
                    <small>Разделяйте {{ $key === 'facts' ? 'факты' : 'абзацы' }} пустой строкой.</small>
                </label>
            @endforeach
        </div>
    </details>
@endforeach
