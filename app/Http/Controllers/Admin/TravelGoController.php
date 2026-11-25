<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\TravelGoContent;
use App\Services\TravelGoCatalog;
use App\Services\TravelGoImages;
use App\Support\ContentStatus;
use App\Support\TravelGoFields;
use App\Support\TravelGoType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TravelGoController extends Controller
{
    public function __construct(private TravelGoCatalog $catalog) {}

    public function home(Request $request): View
    {
        $admin = $this->admin($request);
        $sections = array_filter(TravelGoType::cases(), fn (TravelGoType $type): bool => $admin->hasPermission($type->permission('view')));
        $counts = TravelGoContent::query()->selectRaw('type, status, count(*) as total')
            ->whereIn('type', array_column($sections, 'value'))->groupBy('type', 'status')->get()->groupBy(fn (TravelGoContent $row): string => $row->type->value);

        return view('admin.travelgo.home', compact('sections', 'counts', 'admin'));
    }

    public function index(Request $request, TravelGoType $type): View|JsonResponse
    {
        $this->authorizeAction($request, $type, 'view');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(ContentStatus::class)],
            'countryCode' => ['nullable', 'regex:/\A[A-Z]{2}\z/'],
            'cityId' => ['nullable', 'uuid'],
            'category' => ['nullable', Rule::in(TravelGoFields::CATEGORIES)],
            'bbox' => ['nullable', 'string', 'max:120'],
        ]);

        $query = TravelGoContent::ofType($type)->with(['parent', 'region']);
        if (filled($filters['q'] ?? null)) {
            $query->where(function (Builder $query) use ($filters): void {
                foreach (TravelGoFields::LANGUAGES as $language) {
                    $query->orWhereLike('name->'.$language, '%'.$filters['q'].'%');
                }
                $query->orWhereLike('country_code', '%'.$filters['q'].'%');
            });
        }
        if (isset($filters['countryCode'])) {
            $country = $this->catalog->find(TravelGoType::Country, $filters['countryCode']);
            if (in_array($type, [TravelGoType::Place, TravelGoType::Route], true)) {
                $query->whereHas('parent', fn (Builder $query) => $query->where('parent_id', $country->id));
            } else {
                $query->where($type === TravelGoType::Country ? 'id' : 'parent_id', $country->id);
            }
        }
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['cityId'] ?? null, fn (Builder $query, string $id) => $query->where('parent_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('data->category', $category));
        if (isset($filters['bbox'])) {
            $parts = explode(',', $filters['bbox']);
            if (count($parts) !== 4 || count(array_filter($parts, is_numeric(...))) !== 4) {
                throw ValidationException::withMessages(['bbox' => 'Укажите minLng,minLat,maxLng,maxLat.']);
            }
            [$minLng, $minLat, $maxLng, $maxLat] = array_map(floatval(...), $parts);
            if ($minLng < -180 || $maxLng > 180 || $minLat < -90 || $maxLat > 90 || $minLng > $maxLng || $minLat > $maxLat) {
                throw ValidationException::withMessages(['bbox' => 'Проверьте границы карты.']);
            }
            $query->whereBetween('longitude', [$minLng, $maxLng])->whereBetween('latitude', [$minLat, $maxLat]);
        }

        $records = $query->orderBy('sort_order')->orderBy('id')->paginate(25)->withQueryString();
        if ($this->api($request)) {
            return response()->json(['items' => $records->getCollection()->map($this->serialize(...)),
                'page' => $records->currentPage(), 'total' => $records->total()]);
        }

        $mapPoints = $type === TravelGoType::Place ? $records->getCollection()->whereNotNull('latitude')
            ->map(fn (TravelGoContent $place): array => ['title' => $place->title(),
                'coordinates' => [$place->longitude, $place->latitude],
                'url' => route('admin.travelgo.edit', [$type, $place->id])])->values()->all() : [];

        return view('admin.travelgo.index', compact('type', 'records', 'filters', 'mapPoints'));
    }

    public function show(Request $request, TravelGoType $type, string $id): JsonResponse
    {
        $admin = $this->authorizeAction($request, $type, 'view');

        return response()->json($this->serialize($this->catalog->find($type, $id), true, $admin));
    }

    public function countriesCities(Request $request, string $country): JsonResponse|View
    {
        $request->merge(['countryCode' => $country]);

        return $this->index($request, TravelGoType::City);
    }

    public function cityPlaces(Request $request, string $city): JsonResponse|View
    {
        $request->merge(['cityId' => $city]);

        return $this->index($request, TravelGoType::Place);
    }

    public function create(Request $request, TravelGoType $type): View
    {
        $this->authorizeAction($request, $type, 'create');

        return $this->editor($request, $type, null);
    }

    public function edit(Request $request, TravelGoType $type, string $id): View
    {
        $admin = $this->admin($request);
        abort_unless(collect(['view', 'edit', 'publish', 'archive', 'delete'])->contains(
            fn (string $action): bool => $admin->hasPermission($type->permission($action)),
        ), 403);

        return $this->editor($request, $type, $this->catalog->find($type, $id));
    }

    private function editor(Request $request, TravelGoType $type, ?TravelGoContent $record): View
    {
        $actor = $this->admin($request);
        $canEdit = $record === null || $actor->hasPermission($type->permission('edit'));
        $canInspect = $record === null || $canEdit || $actor->hasPermission($type->permission('view'));
        $parentType = $type->parentType();
        $parents = ! $canInspect || $parentType === null ? collect() : TravelGoContent::ofType($parentType)->with('parent')->orderBy('sort_order')->get();
        $regions = $canInspect && $type === TravelGoType::City ? TravelGoContent::ofType(TravelGoType::Region)->with('parent')->orderBy('sort_order')->get() : collect();
        $places = $canInspect && $type === TravelGoType::Route && $record !== null
            ? TravelGoContent::ofType(TravelGoType::Place)->where('parent_id', $record->parent_id)->orderBy('sort_order')->get() : collect();
        $stops = $canInspect && $type === TravelGoType::Route && $record !== null ? $record->stops()->get() : collect();
        $routes = $canInspect && $actor->hasPermission(TravelGoType::Route->permission('view')) && $type === TravelGoType::Place && $record !== null
            ? $this->catalog->usingPlace($record) : collect();
        $viewModel = $record === null || ! $canInspect ? [] : $this->serialize($record);
        if ($record !== null && $type === TravelGoType::Route) {
            $viewModel['durationMinutes'] = $record->data['durationOverride'] ?? null;
        }

        $warnings = $record !== null && $canInspect ? $this->catalog->publicationWarnings($record) : [];

        return view('admin.travelgo.edit', compact('type', 'record', 'parents', 'regions', 'places', 'stops', 'routes', 'actor', 'viewModel', 'canEdit', 'canInspect', 'warnings'));
    }

    public function store(Request $request, TravelGoType $type): RedirectResponse|JsonResponse
    {
        $actor = $this->authorizeAction($request, $type, 'create');
        $record = $this->catalog->save($type, null, $this->validateFields($request, $type, null), $actor);

        if ($this->api($request)) {
            return response()->json($this->serialize($record, true, $actor), 201);
        }

        return $this->backToRecord($record, 'Черновик создан.');
    }

    public function update(Request $request, TravelGoType $type, string $id): RedirectResponse|JsonResponse
    {
        $actor = $this->authorizeAction($request, $type, 'edit');
        $current = $this->catalog->find($type, $id);
        $record = $this->catalog->save($type, $id, $this->validateFields($request, $type, $current), $actor);

        return $this->result($request, $record, 'Изменения сохранены.');
    }

    private function validateFields(Request $request, TravelGoType $type, ?TravelGoContent $record): array
    {
        if (! $this->api($request)) {
            $input = $request->validate(['paragraphs' => ['sometimes', 'array'], 'paragraphs.*' => ['array'],
                'paragraphs.*.*' => ['nullable', 'string', 'max:24000']]);
            foreach (TravelGoFields::paragraphFields($type) as $key => $label) {
                if (! isset($input['paragraphs'][$key])) {
                    continue;
                }
                $rows = [];
                foreach ($input['paragraphs'][$key] as $language => $text) {
                    foreach (preg_split('/\R\h*\R/', trim($text ?? ''), flags: PREG_SPLIT_NO_EMPTY) as $index => $paragraph) {
                        $rows[$index][$language] = trim($paragraph);
                    }
                }
                ksort($rows);
                $request->merge([$key => array_values($rows)]);
            }
            if ($type === TravelGoType::Place) {
                $hours = $request->input('hours');
                if ($request->input('hoursMode') === 'always') {
                    $request->merge(['hours' => 'always']);
                } elseif (is_array($hours) && blank($hours['open'] ?? null) && blank($hours['close'] ?? null)) {
                    $request->merge(['hours' => null]);
                }
                if (is_array($request->input('images'))) {
                    $request->merge(['images' => array_values(array_filter($request->input('images'), fn ($image) => is_array($image) && filled($image['url'] ?? null)))]);
                }
            }
            if ($type === TravelGoType::Route) {
                foreach (['start', 'end'] as $point) {
                    $value = $request->input($point);
                    if (is_array($value) && blank($value['placeId'] ?? null) && blank($value['latitude'] ?? null) && blank($value['longitude'] ?? null)) {
                        $request->merge([$point => null]);
                    }
                }
            }
        }

        return $request->validate(TravelGoFields::rules($type, $record));
    }

    public function stops(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $admin = $this->authorizeAction($request, TravelGoType::Route, 'edit');
        $input = $request->all();
        if ($this->api($request) && array_is_list($input)) {
            $input = ['stops' => $input];
        }
        if (! $this->api($request)) {
            $stops = $request->validate(['stops' => ['nullable', 'array', 'max:80']])['stops'] ?? [];
            $input['stops'] = array_values(array_filter($stops, fn ($stop): bool => is_array($stop) && filled($stop['placeId'] ?? null)));
        }
        $data = validator($input, TravelGoFields::stopRules())->validate();

        return $this->result($request, $this->catalog->replaceStops($id, $data['stops'], $admin), 'Остановки сохранены.');
    }

    public function recalculate(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $admin = $this->authorizeAction($request, TravelGoType::Route, 'edit');

        return $this->result($request, $this->catalog->recalculateRoute($id, $admin), 'Линия маршрута пересчитана.');
    }

    public function duplicate(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $admin = $this->authorizeAction($request, TravelGoType::Route, 'create');
        $this->authorizeAction($request, TravelGoType::Route, 'view');
        $record = $this->catalog->duplicateRoute($id, $admin);

        return $this->api($request) ? response()->json($this->serialize($record, true, $admin), 201) : $this->backToRecord($record, 'Копия создана.');
    }

    public function destroy(Request $request, TravelGoType $type, string $id): Response|RedirectResponse
    {
        $actor = $this->authorizeAction($request, $type, 'delete');
        $this->catalog->delete($type, $id, $actor);

        if ($this->api($request)) {
            return response()->noContent();
        }

        return ($actor->hasPermission($type->permission('view'))
            ? redirect()->route('admin.travelgo.index', [$type]) : redirect()->route('admin.travelgo.home'))->with('status', 'Черновик удалён.');
    }

    public function status(Request $request, TravelGoType $type, string $id): RedirectResponse|JsonResponse
    {
        $admin = $this->admin($request);
        abort_unless(collect(['publish', 'edit', 'archive'])->contains(fn (string $action): bool => $admin->hasPermission($type->permission($action))), 403);
        $force = $request->input('force');
        if (in_array($force, ['true', 'false'], true)) {
            $request->merge(['force' => $force === 'true']);
        }
        $input = $request->validate([
            'to' => ['required', Rule::enum(ContentStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'publishAt' => ['nullable', 'date'],
            'force' => ['sometimes', 'boolean'],
        ]);
        $record = $this->catalog->transition($type, $id, ContentStatus::from($input['to']), $input['reason'] ?? null,
            $input['publishAt'] ?? null, $request->boolean('force'), $admin);

        return $this->result($request, $record, 'Статус обновлён.');
    }

    public function publish(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->api($request)) {
            $selected = $request->validate(['selected' => ['required', 'array', 'list', 'max:50'], 'selected.*' => ['string', 'max:48']])['selected'];
            $items = array_map(function (string $value): array {
                [$type, $id] = array_pad(explode(':', $value, 2), 2, '');

                return ['type' => $type, 'id' => $id];
            }, $selected);
            $request->merge(['items' => $items]);
        }
        $input = $request->validate([
            'items' => ['required', 'array', 'list', 'max:50'],
            'items.*' => ['required', 'array:type,id'],
            'items.*.type' => ['required', Rule::enum(TravelGoType::class)],
            'items.*.id' => ['required', 'string', 'max:36'],
        ]);
        $admin = $this->admin($request);
        foreach ($input['items'] as $item) {
            abort_unless($admin->hasPermission(TravelGoType::from($item['type'])->permission('publish')), 403);
        }
        $records = $this->catalog->publishBatch($input['items'], $admin);

        return $this->api($request) ? response()->json(['items' => array_map($this->serialize(...), $records)])
            : back()->with('status', 'Объекты опубликованы.');
    }

    public function publication(Request $request): View
    {
        $admin = $this->admin($request);
        $types = array_filter(TravelGoType::cases(), fn (TravelGoType $type): bool => $admin->hasPermission($type->permission('view')));
        abort_unless($admin->hasPermission('travelgo.publication.view'), 403);
        $filters = $request->validate(['status' => ['nullable', Rule::enum(ContentStatus::class)]]);
        $records = TravelGoContent::query()->whereIn('type', array_column($types, 'value'))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']),
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereIn('status', ['in_review', 'scheduled', 'hidden'])
                    ->orWhere('status_reason', 'publish_check_failed')))
            ->orderBy('publish_at')->orderByDesc('updated_at')->orderBy('id')->paginate(25)->withQueryString();

        return view('admin.travelgo.publication', compact('records', 'filters', 'admin'));
    }

    public function audit(Request $request): View|JsonResponse
    {
        $admin = $this->admin($request);
        abort_unless($admin->hasPermission('travelgo.audit.view'), 403);
        $input = $request->validate(['type' => ['nullable', Rule::enum(TravelGoType::class)], 'id' => ['nullable', 'string', 'max:36']]);
        $types = array_filter(TravelGoType::cases(), fn (TravelGoType $type): bool => $admin->hasPermission($type->permission('view')));
        if (isset($input['id']) && ! Str::isUuid($input['id'])) {
            abort_unless(($input['type'] ?? null) === TravelGoType::Country->value && $admin->hasPermission(TravelGoType::Country->permission('view')), 404);
            $input['id'] = $this->catalog->find(TravelGoType::Country, $input['id'])->id;
        }
        $visibleTypes = array_column($types, 'value');
        if ($admin->is_superadmin) {
            $visibleTypes[] = 'staff';
        }
        $events = DB::table('travelgo_audit_events')->whereIn('type', $visibleTypes)
            ->when($input['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($input['id'] ?? null, fn ($query, string $id) => $query->where('content_id', $id))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $events->through(fn (object $event): object => (object) array_replace((array) $event, ['details' => json_decode($event->details, true)]));

        return $this->api($request) ? response()->json($events) : view('admin.travelgo.audit', compact('events', 'types', 'input'));
    }

    public function upload(Request $request, TravelGoType $type, string $id, TravelGoImages $images): RedirectResponse|JsonResponse
    {
        $actor = $this->authorizeAction($request, $type, 'edit');
        abort_unless(in_array($type, [TravelGoType::City, TravelGoType::Place, TravelGoType::Route], true), 404);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240'], 'credit' => ['nullable', 'string', 'max:2000']]);
        $this->catalog->find($type, $id);
        $image = $images->store($input['file'], $type->value);
        $url = route('admin.travelgo.media', [$type, $image, 1200]);

        try {
            $record = $this->catalog->attachImage($type, $id, $url, $input['credit'] ?? null, $actor);
        } catch (Throwable $failure) {
            $images->remove($type->value, $image);

            throw $failure;
        }

        return $this->api($request) ? response()->json(['fileUrl' => $url, 'thumbnailUrl' => route('admin.travelgo.media', [$type, $image, 400])], 201)
            : $this->backToRecord($record, 'Фото загружено.');
    }

    public function media(Request $request, TravelGoType $type, string $image, int $size): StreamedResponse
    {
        $admin = $this->admin($request);
        abort_unless($admin->hasPermission($type->permission('view')) || $admin->hasPermission($type->permission('edit')), 403);
        $path = TravelGoImages::path($type->value, $image, $size);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, 'image.webp', ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function serialize(TravelGoContent $record, bool $detailed = false, ?AdminUser $admin = null): array
    {
        $data = $record->data;
        unset($data['durationOverride']);
        $attributes = [
            'id' => $record->id, 'status' => $record->status->value, 'statusReason' => $record->status_reason,
            'publishAt' => $record->publish_at?->toISOString(), 'publishedAt' => $record->published_at?->toISOString(),
            'statusChangedAt' => $record->status_changed_at?->toISOString(), 'statusChangedBy' => $record->status_changed_by,
            'updatedAt' => $record->updated_at?->toISOString(), 'sortOrder' => $record->sort_order,
        ];
        if ($record->type === TravelGoType::Country) {
            $attributes['code'] = $record->country_code;
        }
        if (in_array($record->type, [TravelGoType::City, TravelGoType::Region], true)) {
            $attributes['countryCode'] = $record->parent?->country_code;
        }
        if (in_array($record->type, [TravelGoType::City, TravelGoType::Place, TravelGoType::Route], true)) {
            $attributes['latitude'] = $record->latitude;
            $attributes['longitude'] = $record->longitude;
        }
        $attributes[$record->type === TravelGoType::Route ? 'title' : 'name'] = $record->name;
        if ($record->type === TravelGoType::City) {
            $attributes['regionId'] = $record->region_id;
        }
        if (in_array($record->type, [TravelGoType::Route, TravelGoType::Place], true)) {
            $attributes['cityId'] = $record->parent_id;
        }
        if ($detailed) {
            $attributes['warnings'] = $this->catalog->publicationWarnings($record);
        }
        if ($detailed && $record->type === TravelGoType::Route) {
            $attributes['stops'] = $record->stops()->get()->map(fn (TravelGoContent $place): array => [
                'placeId' => $place->id, 'visitMinutes' => (int) $place->pivot->visit_minutes, 'note' => json_decode($place->pivot->note ?? 'null', true),
            ])->all();
        }
        if ($detailed && $record->type === TravelGoType::Place && $admin?->hasPermission(TravelGoType::Route->permission('view'))) {
            $attributes['routes'] = $this->catalog->usingPlace($record)->map(fn (TravelGoContent $route): array => ['id' => $route->id, 'title' => $route->name])->all();
        }

        return $attributes + $data;
    }

    private function api(Request $request): bool
    {
        return $request->is('admin/v1/*');
    }

    private function result(Request $request, TravelGoContent $record, string $message): RedirectResponse|JsonResponse
    {
        return $this->api($request) ? response()->json($this->serialize($record, true, $this->admin($request))) : $this->backToRecord($record, $message);
    }

    private function backToRecord(TravelGoContent $record, string $message): RedirectResponse
    {
        $admin = auth('admin')->user();
        if (collect(['view', 'edit', 'publish', 'archive', 'delete'])->contains(fn (string $action): bool => $admin->hasPermission($record->type->permission($action)))) {
            return redirect()->route('admin.travelgo.edit', [$record->type, $record->publicKey()])->with('status', $message);
        }

        return redirect()->route('admin.travelgo.home')->with('status', $message);
    }

    private function admin(Request $request): AdminUser
    {
        return $request->user('admin');
    }

    private function authorizeAction(Request $request, TravelGoType $type, string $action): AdminUser
    {
        $admin = $this->admin($request);
        abort_unless($admin->hasPermission($type->permission($action)), 403);

        return $admin;
    }
}
