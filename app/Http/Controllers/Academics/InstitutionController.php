<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\UpdateInstitutionRequest;
use App\Http\Requests\Academics\UploadInstitutionLogoRequest;
use App\Http\Resources\Academics\InstitutionResource;
use App\Services\Academics\InstitutionService;
use Illuminate\Http\JsonResponse;

class InstitutionController extends Controller
{
    public function __construct(
        protected InstitutionService $institutionService
    ) {}

    public function show(): JsonResponse
    {
        $institution = $this->institutionService->getProfile();

        return response()->json([
            'data' => new InstitutionResource($institution),
        ]);
    }

    public function update(UpdateInstitutionRequest $request): JsonResponse
    {
        $institution = $this->institutionService->updateProfile(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new InstitutionResource($institution),
        ]);
    }

    public function uploadLogo(UploadInstitutionLogoRequest $request): JsonResponse
    {
        $institution = $this->institutionService->uploadLogo(
            $request->file('logo'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new InstitutionResource($institution),
        ]);
    }
}
