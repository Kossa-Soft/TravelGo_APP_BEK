<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileSession;
use App\Models\TravelGoContent;
use App\Services\MobileAuth;
use App\Support\CatalogPresentation;
use App\Support\CatalogQuery;
use App\Support\ContentStatus;
use App\Support\TravelGoType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SavedRouteController extends Controller
{
    public function __construct(private readonly MobileAuth $auth, private readonly CatalogPresentation $presentation) {}

    public function index(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $input = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,50'], 'cursor' => ['nullable', 'string', 'max:2048']]);
        $saved = DB::table('travelgo_saved_routes')->where('user_id', $session->user_id)
            ->orderByDesc('created_at')->orderByDesc('route_id')->cursorPaginate((int) ($input['limit'] ?? 20), cursor: $input['cursor'] ?? null);
        $ids = array_column($saved->items(), 'route_id');
        $all = TravelGoContent::ofType(TravelGoType::Route)->whereIn('id', $ids)->get(['id', 'slug', 'status'])->keyBy('id');
        $visible = TravelGoContent::visible(TravelGoType::Route)->whereIn('id', $ids)->with(CatalogQuery::routeRelations())->get()->keyBy('id');
        $language = CatalogController::language($request);
        $items = [];
        foreach ($ids as $id) {
            if (isset($visible[$id])) {
                $items[] = $this->presentation->routeCard($visible[$id], $language, true);
            } elseif (isset($all[$id])) {
                $items[] = ['id' => $all[$id]->mobileKey(),
                    'status' => $all[$id]->status === ContentStatus::Archived ? 'archived' : 'hidden'];
            }
        }

        return response()->json(['items' => $items, 'nextCursor' => $saved->nextCursor()?->encode()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $id): Response
    {
        $session = $this->session($request);
        DB::transaction(function () use ($session, $id): void {
            $this->auth->lockUser($session);
            $route = CatalogQuery::byMobileId(TravelGoContent::visible(TravelGoType::Route), $id)->firstOrFail();
            $this->insert($session->user_id, [$route->id]);
        });

        return response()->noContent();
    }

    public function destroy(Request $request, string $id): Response
    {
        $session = $this->session($request);
        DB::transaction(function () use ($session, $id): void {
            $this->auth->lockUser($session);
            $route = CatalogQuery::byMobileId(TravelGoContent::ofType(TravelGoType::Route), $id)->first();
            if ($route !== null) {
                DB::table('travelgo_saved_routes')->where(['user_id' => $session->user_id, 'route_id' => $route->id])->delete();
            }
        });

        return response()->noContent();
    }

    public function import(Request $request): JsonResponse
    {
        $session = $this->session($request);
        $input = $request->validate([
            'routeIds' => ['present', 'array', 'list', 'max:100'],
            'routeIds.*' => ['required', 'string', 'max:160', 'regex:/\A[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*\z/'],
        ]);
        $result = DB::transaction(function () use ($session, $input): array {
            $this->auth->lockUser($session);
            $saved = [];
            $unavailable = [];
            foreach (array_unique($input['routeIds']) as $id) {
                $route = CatalogQuery::byMobileId(TravelGoContent::visible(TravelGoType::Route), $id)->first();
                if ($route === null) {
                    $unavailable[] = $id;
                } else {
                    $saved[$route->mobileKey()] = $route->id;
                }
            }
            $this->insert($session->user_id, array_values($saved));

            return ['savedIds' => array_keys($saved), 'unavailableIds' => $unavailable];
        });

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    private function insert(int $userId, array $routeIds): void
    {
        if ($routeIds !== []) {
            $time = now();
            DB::table('travelgo_saved_routes')->insertOrIgnore(array_map(
                fn (string $id): array => ['user_id' => $userId, 'route_id' => $id, 'created_at' => $time], $routeIds,
            ));
        }
    }

    private function session(Request $request): MobileSession
    {
        return $this->auth->authenticate($request->bearerToken());
    }
}
