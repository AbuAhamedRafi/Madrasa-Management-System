<?php

namespace App\Http\Controllers\Academics;

use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicOfferingRequest;
use App\Http\Requests\Academics\UpdateAcademicOfferingRequest;
use App\Http\Resources\Academics\AcademicOfferingResource;
use App\Repositories\Academics\AcademicOfferingRepository;
use App\Services\Academics\AcademicOfferingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicOfferingController extends Controller
{
    public function __construct(
        protected AcademicOfferingRepository $offeringRepository,
        protected AcademicOfferingService $offeringService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->filled('academic_period_id')) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'academic_period_id query parameter is required.',
                422,
                ['academic_period_id' => ['academic_period_id query parameter is required.']]
            );
        }

        $trackId = $request->filled('academic_track_id') ? (int) $request->query('academic_track_id') : null;
        $active = $request->has('active')
            ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        $paginator = $this->offeringRepository->paginateByPeriod(
            (int) $request->query('academic_period_id'),
            $trackId,
            $active,
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 50)
        );

        return response()->json([
            'data' => AcademicOfferingResource::collection($paginator->items()),
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
        $offering = $this->offeringRepository->findById($id);
        if (! $offering) {
            abort(404, 'Academic offering not found.');
        }

        return response()->json([
            'data' => new AcademicOfferingResource($offering),
        ]);
    }

    public function store(StoreAcademicOfferingRequest $request): JsonResponse
    {
        $offering = $this->offeringService->createOffering(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicOfferingResource($offering),
        ], 201);
    }

    public function update(UpdateAcademicOfferingRequest $request, int $id): JsonResponse
    {
        $offering = $this->offeringRepository->findById($id);
        if (! $offering) {
            abort(404, 'Academic offering not found.');
        }

        $updated = $this->offeringService->updateOffering(
            $offering,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new AcademicOfferingResource($updated),
        ]);
    }
}
