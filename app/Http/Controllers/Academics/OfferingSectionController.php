<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreOfferingSectionRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateOfferingSectionRequest;
use App\Http\Resources\Academics\OfferingSectionResource;
use App\Repositories\Academics\AcademicOfferingRepository;
use App\Repositories\Academics\OfferingSectionRepository;
use App\Services\Academics\OfferingSectionService;
use Illuminate\Http\JsonResponse;

class OfferingSectionController extends Controller
{
    public function __construct(
        protected AcademicOfferingRepository $offeringRepository,
        protected OfferingSectionRepository $sectionRepository,
        protected OfferingSectionService $sectionService
    ) {}

    public function show(int $id): JsonResponse
    {
        $section = $this->sectionRepository->findById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        return response()->json([
            'data' => new OfferingSectionResource($section),
        ]);
    }

    public function store(StoreOfferingSectionRequest $request, int $id): JsonResponse
    {
        $offering = $this->offeringRepository->findById($id);
        if (! $offering) {
            abort(404, 'Academic offering not found.');
        }

        $section = $this->sectionService->create(
            $offering,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new OfferingSectionResource($section),
        ], 201);
    }

    public function update(UpdateOfferingSectionRequest $request, int $id): JsonResponse
    {
        $section = $this->sectionRepository->findById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        $updated = $this->sectionService->update(
            $section,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new OfferingSectionResource($updated),
        ]);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $section = $this->sectionRepository->findById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        $updated = $this->sectionService->updateStatus(
            $section,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new OfferingSectionResource($updated),
        ]);
    }
}

