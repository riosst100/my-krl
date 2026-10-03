<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StationResource;
use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100']]);

        $stations = Station::active()->search($request->input('search'))->orderBy('name')->get();

        return StationResource::collection($stations)->additional(['meta' => ['total' => $stations->count()]]);
    }

    public function show(Station $station): StationResource
    {
        return new StationResource($station);
    }
}
