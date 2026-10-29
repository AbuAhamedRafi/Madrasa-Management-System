<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academics\UpdateInstitutionRequest;
use App\Http\Requests\Academics\UploadInstitutionLogoRequest;
use App\Models\Institution;
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
            'data' => $this->format($institution),
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
            'data' => $this->format($institution),
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
            'data' => $this->format($institution),
        ]);
    }

    protected function format(Institution $institution): array
    {
        return [
            'id' => $institution->id,
            'code' => $institution->code,
            'name_bn' => $institution->name_bn,
            'name_en' => $institution->name_en,
            'address_bn' => $institution->address_bn,
            'address_en' => $institution->address_en,
            'phone' => $institution->phone,
            'logo_url' => $institution->logo_path,
            'default_locale' => $institution->default_locale,
            'timezone' => $institution->timezone,
            'updated_at' => $institution->updated_at?->toISOString(),
        ];
    }
}

