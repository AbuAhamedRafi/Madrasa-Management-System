<?php

namespace App\Http\Controllers\Academics;

use App\Exceptions\AcademicDomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\StoreAcademicOfferingRequest;
use App\Http\Requests\Academics\StoreOfferingSectionRequest;
use App\Http\Requests\Academics\UpdateAcademicOfferingRequest;
use App\Http\Requests\Academics\UpdateCatalogStatusRequest;
use App\Http\Requests\Academics\UpdateOfferingSectionRequest;
use App\Models\AcademicOffering;
use App\Models\OfferingSection;
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

    public function indexOfferings(Request $request): JsonResponse
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
            'data' => collect($paginator->items())->map(fn (AcademicOffering $o) => $this->formatOffering($o))->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showOffering(int $id): JsonResponse
    {
        $offering = $this->offeringRepository->findById($id);
        if (! $offering) {
            abort(404, 'Academic offering not found.');
        }

        return response()->json([
            'data' => $this->formatOffering($offering),
        ]);
    }

    public function storeOffering(StoreAcademicOfferingRequest $request): JsonResponse
    {
        $offering = $this->offeringService->createOffering(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatOffering($offering),
        ], 201);
    }

    public function updateOffering(UpdateAcademicOfferingRequest $request, int $id): JsonResponse
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
            'data' => $this->formatOffering($updated),
        ]);
    }

    public function showSection(int $id): JsonResponse
    {
        $section = $this->offeringRepository->findSectionById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        return response()->json([
            'data' => $this->formatSection($section),
        ]);
    }

    public function storeSection(StoreOfferingSectionRequest $request, int $id): JsonResponse
    {
        $offering = $this->offeringRepository->findById($id);
        if (! $offering) {
            abort(404, 'Academic offering not found.');
        }

        $section = $this->offeringService->createSection(
            $offering,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatSection($section),
        ], 201);
    }

    public function updateSection(UpdateOfferingSectionRequest $request, int $id): JsonResponse
    {
        $section = $this->offeringRepository->findSectionById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        $updated = $this->offeringService->updateSection(
            $section,
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatSection($updated),
        ]);
    }

    public function updateSectionStatus(UpdateCatalogStatusRequest $request, int $id): JsonResponse
    {
        $section = $this->offeringRepository->findSectionById($id);
        if (! $section) {
            abort(404, 'Offering section not found.');
        }

        $updated = $this->offeringService->updateSectionStatus(
            $section,
            (bool) $request->boolean('is_active'),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => $this->formatSection($updated),
        ]);
    }

    protected function formatOffering(AcademicOffering $o): array
    {
        return [
            'id' => $o->id,
            'academic_period_id' => $o->academic_period_id,
            'program_class_id' => $o->program_class_id,
            'academic_track_id' => $o->academic_track_id,
            'is_active' => $o->is_active,
            'program_class' => $o->relationLoaded('programClass') && $o->programClass ? [
                'id' => $o->programClass->id,
                'code' => $o->programClass->code,
                'name_bn' => $o->programClass->name_bn,
                'name_en' => $o->programClass->name_en,
                'stage_number' => $o->programClass->stage_number,
            ] : null,
            'track' => $o->relationLoaded('track') && $o->track ? [
                'id' => $o->track->id,
                'code' => $o->track->code,
                'name_bn' => $o->track->name_bn,
                'name_en' => $o->track->name_en,
            ] : null,
            'sections' => $o->relationLoaded('sections')
                ? $o->sections->map(fn (OfferingSection $s) => $this->formatSection($s))->values()
                : [],
        ];
    }

    protected function formatSection(OfferingSection $s): array
    {
        return [
            'id' => $s->id,
            'academic_offering_id' => $s->academic_offering_id,
            'offering_id' => $s->academic_offering_id,
            'code' => $s->code,
            'name_bn' => $s->name_bn,
            'name_en' => $s->name_en,
            'capacity' => $s->capacity,
            'is_active' => $s->is_active,
        ];
    }
}

