# 03. API контента для мобильного приложения

Что приложение читает: маршруты, страны, города, гиды, сохранённые.
Модель данных — в [02-content-model.md](02-content-model.md), общие
правила — в [00-api-guidelines.md](00-api-guidelines.md).

Все пути от `/v1`. Тексты возвращаются на языке из `Accept-Language`
(запасной — английский), то есть **строками, а не объектами `Localized`**.
Приложение показывает только опубликованное (`published`).

Чтение контента доступно и без входа. Если токен есть — в ответах
заполняется `isSaved`.

## Главная

Под картой — поиск и 4 вкладки:

| Вкладка | Что показывает | Запрос |
|---|---|---|
| Рекомендовано | Маршруты TravelGo и выбор редакции рядом с пользователем | `GET /routes?feed=recommended&lat=…&lng=…` |
| Ваші маршрути | Маршруты, созданные самим пользователем (пока пусто) | `GET /me/routes` |
| Популярні | Самые проходимые, с рейтингом (место в топе — порядок в выдаче) | `GET /routes?feed=popular&lat=…&lng=…` |
| Маршрути гідів | Гиды с их маршрутами | `GET /guides?lat=…&lng=…` |

`lat`/`lng` — где сейчас пользователь (если дал доступ к геолокации).
Тогда ближайшие города идут первыми. Без координат — общая выдача.

### Карточка маршрута в списке — `RouteCard`

```json
{
  "id": "9b0e…",
  "title": "Класичний Париж",
  "description": "Найвідоміші пам'ятки столиці Франції в одному маршруті.",
  "coverImage": "https://cdn…/classic-paris.jpg",
  "city": { "id": "…", "name": "Париж" },
  "country": { "code": "FR", "name": "Франція" },
  "durationMinutes": 350,
  "distanceKm": 7.4,
  "placesCount": 6,
  "badge": "popular",
  "rating": 4.8,
  "reviewsCount": 1240,
  "usedCount": 5200,
  "travelersCount": 3100,
  "travelers": [ { "name": "Олена", "avatarUrl": "…" } ],
  "source": { "kind": "guide", "guide": { "id": "…", "name": "Жан-П'єр", "photo": "…", "verified": true } },
  "isSaved": false
}
```

`source` для `user` — просто `{ "kind": "user" }` (автора не показываем).

## Поиск

`GET /routes?q=париж`

Ищет по названию маршрута, городу и стране **на всех языках** и без
учёта регистра и диакритики (`espana` находит «España»). Возвращает
`RouteCard` в виде списка с пагинацией.

Фильтры (можно вместе с `q`): `countryCode=FR`, `cityId=…`, `guideId=…`.

## Маршрут целиком

`GET /routes/{id}`

Всё из `RouteCard` плюс:

```json
{
  "about": ["Перший абзац…", "Другий абзац…"],
  "timeZone": "Europe/Paris",
  "stops": [
    {
      "position": 1,
      "visitMinutes": 20,
      "note": null,
      "place": {
        "id": "…",
        "name": "Ейфелева вежа",
        "description": "Символ Парижа та одна з найвідоміших споруд у світі.",
        "address": "Champ de Mars, 5 Av. Anatole France, 75007 Paris",
        "latitude": 48.8584,
        "longitude": 2.2945,
        "hours": { "open": "09:30", "close": "23:45" },
        "images": ["https://cdn…/eiffel.jpg"],
        "wiki": "uk:Ейфелева_вежа"
      }
    }
  ]
}
```

Приложение рисует линию маршрута по точкам остановок и само считает
«Открыто / Закрыто» по `hours` и `timeZone`.

## Страны и города

- `GET /countries` — страны, где есть опубликованные маршруты:
  `[{ "code": "FR", "name": "Франція", "routesCount": 6 }]`.
- `GET /countries/{code}/cities` —
  `[{ "id": "…", "name": "Париж", "routesCount": 6, "coverImage": "…", "latitude": 48.85, "longitude": 2.35 }]`.
- `GET /cities/{id}` — город (как элемент списка выше + `timeZone`).

Нужны для фильтра «Країна → місто» и будущего каталога.

## Гиды

- `GET /guides` — список (с `lat`/`lng` — ближайшие первыми):

  ```json
  {
    "id": "…",
    "name": "Жан-П'єр Дюбуа",
    "title": "Гід по Парижу",
    "photo": "…",
    "cover": "…",
    "verified": true,
    "online": false,
    "rating": 4.9,
    "reviewsCount": 320,
    "usedCount": 2100,
    "routesCount": 2
  }
  ```

- `GET /guides/{id}` — то же + `bio` (абзацы), `contacts`, `cities`.
- `GET /guides/{id}/routes` — его маршруты (`RouteCard`).

## Сохранённые (нужен вход)

| Запрос | Что делает |
|---|---|
| `GET /me/saved-routes` | Список `RouteCard` (новые сверху) |
| `PUT /me/saved-routes/{routeId}` | Сохранить (повторный вызов — не ошибка). Ответ `204` |
| `DELETE /me/saved-routes/{routeId}` | Убрать. Ответ `204` |
| `POST /me/saved-routes/import` | `{ "routeIds": [...] }` — перенести закладки, сохранённые на телефоне до входа |

Фильтр «Країна / місто» во вкладке «Збережені» приложение делает само
по полям `city` и `country` карточки.

## Кэширование

- Списки и маршруты меняются редко: отдавать `ETag` и `Cache-Control:
  max-age=300`. Приложение будет переспрашивать с `If-None-Match`.
- Картинки — через CDN, с долгим кэшем; при замене картинки — новый URL.

## Что приложение берёт не из нашего API

- Сама карта и точки «что рядом» (кафе, музеи) — из открытых карт
  (OpenStreetMap / OpenFreeMap). Бэкенду это делать не нужно.
- Фото мест — из нашего API (`images`), а если пусто — из Википедии по `wiki`.
