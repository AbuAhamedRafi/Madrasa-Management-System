<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\Institution;
use App\Models\User;
use App\Repositories\Academics\InstitutionRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InstitutionService
{
    public function __construct(
        protected InstitutionRepository $institutionRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function getProfile(): Institution
    {
        return $this->institutionRepository->getDefault();
    }

    public function updateProfile(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): Institution {
        $institution = $this->institutionRepository->getDefault();

        if (isset($data['code']) && strtoupper(trim($data['code'])) !== $institution->code) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Institution code is immutable and cannot be changed.',
                422,
                ['code' => ['Institution code cannot be modified after creation.']]
            );
        }

        $allowed = collect($data)->only([
            'name_bn',
            'name_en',
            'address_bn',
            'address_en',
            'phone',
            'default_locale',
            'timezone',
        ])->toArray();

        $before = $institution->only(array_keys($allowed));
        $updated = $this->institutionRepository->update($institution, $allowed);

        $this->auditLogger->record(
            'INSTITUTION_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(array_keys($allowed)),
            $ip,
            $requestId,
            null,
            'Institution',
            $updated->id
        );

        return $updated;
    }

    public function uploadLogo(
        UploadedFile $file,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): Institution {
        $institution = $this->institutionRepository->getDefault();

        // Deep content inspection to reject spoofed non-image files
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false || ! in_array($imageInfo['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Uploaded file is not a valid PNG or JPEG image.',
                422,
                ['logo' => ['Uploaded file must be a genuine PNG or JPEG image.']]
            );
        }

        $extension = $imageInfo['mime'] === 'image/png' ? 'png' : 'jpg';
        $canonicalKey = 'institution/logo_' . Str::lower(Str::random(10)) . '.' . $extension;

        Storage::disk('public')->put($canonicalKey, file_get_contents($file->getRealPath()));

        $before = ['logo_path' => $institution->logo_path];
        $updated = $this->institutionRepository->update($institution, [
            'logo_path' => '/storage/' . $canonicalKey,
        ]);

        $this->auditLogger->record(
            'INSTITUTION_LOGO_UPDATED',
            $actor?->id,
            null,
            $before,
            ['logo_path' => $updated->logo_path],
            $ip,
            $requestId,
            null,
            'Institution',
            $updated->id
        );

        return $updated;
    }
}

