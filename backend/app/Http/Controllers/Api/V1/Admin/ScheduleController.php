<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ScheduleFilterRequest;
use App\Http\Resources\ScheduleResource;
use App\Services\ScheduleSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ScheduleController extends Controller
{
    public function index(ScheduleFilterRequest $request, ScheduleSearchService $search): AnonymousResourceCollection
    {
        $page = $search->query($request->filters(), activeStationsOnly: false)
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return ScheduleResource::collection($page)->additional(['meta' => ['date' => $request->serviceDate()]]);
    }
}
