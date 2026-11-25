<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ContentException;
use App\Http\Controllers\Controller;
use App\Services\TravelGoGeo;
use App\Support\TravelGoType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TravelGoGeoController extends Controller
{
    public function search(Request $request, TravelGoGeo $geo): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->hasPermission(TravelGoType::Place->permission('create'))
            || $admin->hasPermission(TravelGoType::Place->permission('edit'))
            || $admin->hasPermission(TravelGoType::City->permission('create'))
            || $admin->hasPermission(TravelGoType::City->permission('edit')), 403);
        $query = $request->validate(['q' => ['required', 'string', 'min:3', 'max:160']])['q'];
        $matches = $geo->search($query);
        if ($matches === null) {
            throw new ContentException('GEOCODER_UNAVAILABLE', 503, 'Поиск адресов недоступен. Укажите координаты на карте.');
        }

        return response()->json(['items' => $matches])->header('Cache-Control', 'private, no-store');
    }

    public function timeZone(Request $request, TravelGoGeo $geo): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin->hasPermission(TravelGoType::City->permission('create'))
            || $admin->hasPermission(TravelGoType::City->permission('edit')), 403);
        $point = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);
        $timeZone = $geo->timeZone((float) $point['lat'], (float) $point['lng']);
        if ($timeZone === null) {
            throw new ContentException('TIMEZONE_UNAVAILABLE', 503, 'Автоопределение часового пояса недоступно.');
        }

        return response()->json(['timeZone' => $timeZone])->header('Cache-Control', 'private, no-store');
    }
}
