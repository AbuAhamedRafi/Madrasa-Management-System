<?php

namespace App\Http\Controllers\Academics;

use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicTrackRequest;
use App\Http\Requests\Academics\UpdateAcademicTrackRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Resources\Academics\AcademicTrackResource;
use App\Repositories\Academics\AcademicTrackRepository;
use App\Services\Academics\AcademicTrackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicTrackController extends Controller
{
    public function __construct(
        protected AcademicTrackRepository $trackRepository,
        protected AcademicTrackService $trackService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->filled('program_id')) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'program_id query parameter is required.',
                422,
                ['program_id' => ['program_id query parameter is required.']]
            );
        }

        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->trackRepository->paginate(
            (int) $request->query('program_id'),
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50)
        );

        return response()->json([
            'data' => AcademicTrackResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        return response()->json([
            'data' => new AcademicTrackResource($track),
        ]);
    }

    public function store(StoreAcademicTrackRequest $request): JsonResponse
    {
        $track = $this->trackService->create(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicTrackResource($track),
        ], 201);
    }

    public function update(UpdateAcademicTrackRequest $request, int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        $updated = $this->trackService->update(
            $track,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicTrackResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $track = $this->trackRepository->findById($id);
        if (! $track) {
            abort(404, 'Academic track not found.');
        }

        $updated = $this->trackService->updateStatus(
            $track,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicTrackResource($updated),
        ]);
    }
}
