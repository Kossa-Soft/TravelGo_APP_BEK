const formCountry = document.querySelector('[data-country]');
const formRegion = document.querySelector('[data-region]');

if (formCountry && formRegion) {
    const updateRegions = () => {
        for (const option of formRegion.options) {
            option.hidden = Boolean(option.dataset.countryCode && option.dataset.countryCode !== formCountry.value);
            if (option.hidden && option.selected) formRegion.value = '';
        }
    };
    formCountry.addEventListener('change', updateRegions);
    updateRegions();
}

for (const form of document.querySelectorAll('[data-status-form]')) {
    const select = form.querySelector('select[name="to"]');
    const time = form.querySelector('input[name="publishAt"]');
    const reason = form.querySelector('input[name="reason"]');
    const syncStatus = () => {
        const scheduled = select.value === 'scheduled';
        time.required = scheduled;
        time.disabled = !scheduled;
        time.closest('label').hidden = !scheduled;
        reason.required = ['hidden', 'rejected'].includes(select.value);
    };
    select.addEventListener('change', syncStatus);
    syncStatus();
}

for (const form of document.querySelectorAll('[data-confirm-delete]')) {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirmDelete)) event.preventDefault();
    });
}

const stopsForm = document.querySelector('[data-stops-form]');
if (stopsForm) {
    const list = stopsForm.querySelector('[data-stop-list]');
    const template = stopsForm.querySelector('[data-stop-template]');
    const addButton = stopsForm.querySelector('[data-add-stop]');
    let dragging = null;

    function updateStops() {
        [...list.children].forEach((row, index) => {
            for (const input of row.querySelectorAll('[name^="stops["]')) {
                input.name = input.name.replace(/^stops\[[^\]]+\]/, 'stops[' + index + ']');
            }
            row.draggable = true;
            row.querySelector('[data-stop-move="-1"]').disabled = index === 0;
            row.querySelector('[data-stop-move="1"]').disabled = index === list.children.length - 1;
        });
        addButton.disabled = list.children.length >= 80;
    }

    addButton.addEventListener('click', () => {
        list.append(template.content.cloneNode(true));
        updateStops();
        list.lastElementChild.querySelector('select').focus();
    });

    list.addEventListener('click', event => {
        const button = event.target.closest('button');
        const row = button?.closest('[data-stop-item]');
        if (!row) return;
        if (button.hasAttribute('data-stop-remove')) {
            row.remove();
            addButton.focus();
        } else if (button.dataset.stopMove === '-1') {
            row.previousElementSibling?.before(row);
        } else if (button.dataset.stopMove === '1') {
            row.nextElementSibling?.after(row);
        }
        updateStops();
    });

    list.addEventListener('dragstart', event => {
        dragging = event.target.closest('[data-stop-item]');
        if (!dragging) return;
        dragging.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', '');
    });
    list.addEventListener('dragover', event => {
        if (!dragging) return;
        event.preventDefault();
        const row = event.target.closest('[data-stop-item]');
        if (!row || row === dragging) return;
        const midpoint = row.getBoundingClientRect().top + row.offsetHeight / 2;
        if (event.clientY < midpoint) row.before(dragging);
        else row.after(dragging);
    });
    list.addEventListener('drop', event => event.preventDefault());
    list.addEventListener('dragend', () => {
        dragging?.classList.remove('is-dragging');
        dragging = null;
        updateStops();
    });
    updateStops();
}

function drawContentMap(container) {
    if (!window.maplibregl) return;
    const form = container.closest('form');
    const latInput = form?.querySelector('[name="latitude"]');
    const lngInput = form?.querySelector('[name="longitude"]');
    const radiusInput = form?.querySelector('[name="radiusKm"]');
    const path = JSON.parse(container.dataset.path || 'null');
    const places = JSON.parse(container.dataset.places || '[]');
    const hasPoint = Boolean((latInput?.value || container.dataset.lat) && (lngInput?.value || container.dataset.lng));
    const point = hasPoint ? [Number(lngInput?.value || container.dataset.lng), Number(latInput?.value || container.dataset.lat)]
        : places[0]?.coordinates || [30.5234, 50.4501];
    container.hidden = false;

    const map = new maplibregl.Map({
        container,
        style: 'https://tiles.openfreemap.org/styles/positron',
        center: point,
        zoom: hasPoint || places.length ? 12 : 5,
        scrollZoom: false
    });
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    const marker = new maplibregl.Marker({ color: '#07549b' });
    if (hasPoint && !path) marker.setLngLat(point).addTo(map);

    for (const place of places) {
        const link = document.createElement('a');
        link.textContent = place.title;
        link.href = place.url;
        new maplibregl.Marker({ color: '#07549b' }).setLngLat(place.coordinates)
            .setPopup(new maplibregl.Popup().setDOMContent(link)).addTo(map);
    }

    function renderRadius(center) {
        const source = map.getSource('city-radius');
        if (!source || !radiusInput || Number(radiusInput.value) <= 0) return;
        const rad = Math.PI / 180;
        const angularDistance = Number(radiusInput.value) / 6371;
        const lat = center[1] * rad;
        const lng = center[0] * rad;
        const coordinates = Array.from({ length: 65 }, (_, index) => {
            const bearing = index % 64 * Math.PI / 32;
            const nextLat = Math.asin(Math.sin(lat) * Math.cos(angularDistance) + Math.cos(lat) * Math.sin(angularDistance) * Math.cos(bearing));
            const nextLng = lng + Math.atan2(Math.sin(bearing) * Math.sin(angularDistance) * Math.cos(lat), Math.cos(angularDistance) - Math.sin(lat) * Math.sin(nextLat));
            return [nextLng / rad, nextLat / rad];
        });
        source.setData({ type: 'Polygon', coordinates: [coordinates] });
    }

    map.on('load', () => {
        if (radiusInput) {
            map.addSource('city-radius', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            map.addLayer({ id: 'city-radius', type: 'fill', source: 'city-radius', paint: { 'fill-color': '#1464f4', 'fill-opacity': 0.14 } });
            if (hasPoint) renderRadius(point);
        }
        if (path) {
            map.addSource('route-path', { type: 'geojson', data: path });
            map.addLayer({ id: 'route-path', type: 'line', source: 'route-path', paint: { 'line-color': '#1464f4', 'line-width': 4 } });
            new maplibregl.Marker({ color: '#07549b' }).setLngLat(path.coordinates[0]).addTo(map);
            new maplibregl.Marker({ color: '#ff6500' }).setLngLat(path.coordinates.at(-1)).addTo(map);
        }
        const points = path?.coordinates || places.map(place => place.coordinates);
        if (points.length) {
            const bounds = points.reduce((bbox, coordinates) => bbox.extend(coordinates), new maplibregl.LngLatBounds());
            map.fitBounds(bounds, { padding: 44, maxZoom: 15, duration: 0 });
        }
    });

    if (container.dataset.editable === '1') {
        map.on('click', ({ lngLat }) => {
            const coordinates = lngLat.wrap().toArray();
            latInput.value = coordinates[1].toFixed(6);
            lngInput.value = coordinates[0].toFixed(6);
            marker.setLngLat(coordinates).addTo(map);
            renderRadius(coordinates);
        });
        const updatePoint = () => {
            if (latInput.value === '' || lngInput.value === '' || !latInput.validity.valid || !lngInput.validity.valid) return;
            const coordinates = [Number(lngInput.value), Number(latInput.value)];
            marker.setLngLat(coordinates).addTo(map);
            map.jumpTo({ center: coordinates });
            renderRadius(coordinates);
        };
        latInput.addEventListener('change', updatePoint);
        lngInput.addEventListener('change', updatePoint);
        radiusInput?.addEventListener('change', updatePoint);
    }
}

for (const container of document.querySelectorAll('[data-content-map]')) {
    try {
        drawContentMap(container);
    } catch {
        container.hidden = true;
    }
}

const geoMessage = document.querySelector('[data-geo-message]');

function showGeoMessage(message) {
    geoMessage.textContent = message;
    geoMessage.hidden = !message;
}

async function requestGeo(url, parameters) {
    const target = new URL(url, window.location.origin);
    target.search = new URLSearchParams(parameters).toString();
    const response = await fetch(target, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        const data = await response.json();
        throw new Error(data.error?.message || data.message || 'Запрос не выполнен.');
    }
    return response.json();
}

const addressSearch = document.querySelector('[data-geo-search]');
if (addressSearch) {
    const input = addressSearch.querySelector('[data-address-query]');
    const submit = addressSearch.querySelector('[data-address-search]');
    const results = addressSearch.querySelector('[data-address-results]');
    const form = addressSearch.closest('form');

    submit.addEventListener('click', async () => {
        if (input.value.trim().length < 3) {
            showGeoMessage('Введите не менее трёх символов адреса.');
            return;
        }
        submit.disabled = true;
        results.replaceChildren();
        showGeoMessage('Поиск адреса…');
        try {
            const data = await requestGeo(addressSearch.dataset.geoSearch, { q: input.value.trim() });
            showGeoMessage(data.items.length ? '' : 'Адрес не найден.');
            for (const item of data.items) {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = item.label;
                button.addEventListener('click', () => {
                    const latitude = form.elements.latitude;
                    const longitude = form.elements.longitude;
                    latitude.value = item.latitude.toFixed(6);
                    longitude.value = item.longitude.toFixed(6);
                    latitude.dispatchEvent(new Event('change'));
                    if (form.elements.address) form.elements.address.value = item.label;
                    results.replaceChildren();
                    showGeoMessage('');
                });
                results.append(button);
            }
        } catch (failure) {
            showGeoMessage(failure.message);
        } finally {
            submit.disabled = false;
        }
    });
}

const timezoneButton = document.querySelector('[data-timezone-url]');
if (timezoneButton) {
    const form = timezoneButton.closest('form');
    timezoneButton.addEventListener('click', async () => {
        const lat = form.elements.latitude.value;
        const lng = form.elements.longitude.value;
        if (lat === '' || lng === '' || !form.elements.latitude.validity.valid || !form.elements.longitude.validity.valid) {
            showGeoMessage('Сначала укажите координаты города.');
            return;
        }
        timezoneButton.disabled = true;
        try {
            const data = await requestGeo(timezoneButton.dataset.timezoneUrl, { lat, lng });
            form.elements.timeZone.value = data.timeZone;
            showGeoMessage('');
        } catch (failure) {
            showGeoMessage(failure.message);
        } finally {
            timezoneButton.disabled = false;
        }
    });
}
