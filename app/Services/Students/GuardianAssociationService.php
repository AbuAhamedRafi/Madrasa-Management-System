<?php

namespace App\Services\Students;

use App\Exceptions\StudentDomainException;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Repositories\AuditLogRepository;
use Illuminate\Support\Facades\DB;

class GuardianAssociationService
{
    public function __construct(
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function addGuardian(Student $student, array $data, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentGuardian
    {
        return DB::transaction(function () use ($student, $data, $actorId, $ipAddress, $requestId) {
            $guardian = $this->resolveGuardian($data, $actorId);

            $alreadyLinked = StudentGuardian::where('student_id', $student->id)
                ->where('guardian_id', $guardian->id)
                ->exists();

            if ($alreadyLinked) {
                throw new StudentDomainException(
                    'GUARDIAN_ALREADY_LINKED',
                    'This guardian is already linked to the student.',
                    409,
                    ['guardian_id' => ['Guardian already linked.']]
                );
            }

            $isPrimary = (bool) ($data['is_primary'] ?? false);
            if ($isPrimary) {
                // Demote current primary guardian
                StudentGuardian::where('student_id', $student->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false, 'updated_by' => $actorId]);
            }

            $smsConsent = (bool) ($data['sms_consent'] ?? false);
            $link = StudentGuardian::create([
                'student_id' => $student->id,
                'guardian_id' => $guardian->id,
                'relation' => $data['relation'],
                'is_primary' => $isPrimary,
                'sms_consent' => $smsConsent,
                'consent_at' => $smsConsent ? now() : null,
                'consent_source' => $data['consent_source'] ?? 'GUARDIAN_MANAGEMENT',
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->auditLogRepository->log(
                actionCode: 'GUARDIAN_LINKED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: null,
                afterMetadata: [
                    'student_id' => $student->student_id,
                    'guardian_id' => $guardian->id,
                    'relation' => $data['relation'],
                    'is_primary' => $isPrimary,
                    'sms_consent' => $smsConsent,
                ],
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: $data['reason'] ?? 'Added secondary guardian',
                entityType: 'Student',
                entityId: $student->id
            );

            return $link->load('guardian');
        });
    }

    public function makePrimary(Student $student, int $guardianId, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentGuardian
    {
        return DB::transaction(function () use ($student, $guardianId, $actorId, $ipAddress, $requestId) {
            $link = StudentGuardian::where('student_id', $student->id)
                ->where('guardian_id', $guardianId)
                ->lockForUpdate()
                ->first();

            if (!$link) {
                throw new StudentDomainException(
                    'GUARDIAN_NOT_LINKED',
                    'Guardian is not associated with this student.',
                    404
                );
            }

            if ($link->is_primary) {
                return $link->load('guardian');
            }

            $prevPrimary = StudentGuardian::where('student_id', $student->id)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->first();

            if ($prevPrimary) {
                $prevPrimary->is_primary = false;
                $prevPrimary->updated_by = $actorId;
                $prevPrimary->save();
            }

            $link->is_primary = true;
            $link->updated_by = $actorId;
            $link->save();

            $this->auditLogRepository->log(
                actionCode: 'PRIMARY_GUARDIAN_CHANGED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: ['primary_guardian_id' => $prevPrimary?->guardian_id],
                afterMetadata: ['primary_guardian_id' => $guardianId],
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: 'Primary guardian designated',
                entityType: 'Student',
                entityId: $student->id
            );

            return $link->load('guardian');
        });
    }

    public function removeGuardian(Student $student, int $guardianId, int $actorId, ?string $ipAddress = null, ?string $requestId = null): void
    {
        DB::transaction(function () use ($student, $guardianId, $actorId, $ipAddress, $requestId) {
            $link = StudentGuardian::where('student_id', $student->id)
                ->where('guardian_id', $guardianId)
                ->first();

            if (!$link) {
                throw new StudentDomainException('GUARDIAN_NOT_LINKED', 'Guardian is not associated with this student.', 404);
            }

            if ($link->is_primary) {
                throw new StudentDomainException(
                    'PRIMARY_GUARDIAN_REQUIRED',
                    'Cannot unlink the primary guardian. Designate another guardian as primary first.',
                    422
                );
            }

            $link->delete();

            $this->auditLogRepository->log(
                actionCode: 'GUARDIAN_UNLINKED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: ['guardian_id' => $guardianId, 'relation' => $link->relation],
                afterMetadata: null,
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: 'Guardian unlinked',
                entityType: 'Student',
                entityId: $student->id
            );
        });
    }

    public function updateConsent(Student $student, int $guardianId, bool $smsConsent, ?string $source, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentGuardian
    {
        return DB::transaction(function () use ($student, $guardianId, $smsConsent, $source, $actorId, $ipAddress, $requestId) {
            $link = StudentGuardian::where('student_id', $student->id)
                ->where('guardian_id', $guardianId)
                ->first();

            if (!$link) {
                throw new StudentDomainException('GUARDIAN_NOT_LINKED', 'Guardian is not associated with this student.', 404);
            }

            $prevConsent = $link->sms_consent;
            $link->sms_consent = $smsConsent;
            $link->consent_at = $smsConsent ? now() : null;
            if (!empty($source)) {
                $link->consent_source = $source;
            }
            $link->updated_by = $actorId;
            $link->save();

            $this->auditLogRepository->log(
                actionCode: 'SMS_CONSENT_UPDATED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: ['sms_consent' => $prevConsent],
                afterMetadata: ['sms_consent' => $smsConsent, 'consent_source' => $link->consent_source],
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: 'SMS consent modified',
                entityType: 'Student',
                entityId: $student->id
            );

            return $link->load('guardian');
        });
    }

    protected function resolveGuardian(array $guardianData, int $actorId): Guardian
    {
        if (!empty($guardianData['id'])) {
            $existing = Guardian::find($guardianData['id']);
            if ($existing) {
                return $existing;
            }
        }

        $phone = preg_replace('/[^0-9+]/', '', trim($guardianData['phone']));

        return Guardian::create([
            'name_bn' => trim($guardianData['name_bn']),
            'name_en' => trim($guardianData['name_en']),
            'phone' => $phone,
            'alternative_phone' => !empty($guardianData['alternative_phone'])
                ? preg_replace('/[^0-9+]/', '', trim($guardianData['alternative_phone']))
                : null,
            'address_bn' => $guardianData['address_bn'] ?? null,
            'address_en' => $guardianData['address_en'] ?? null,
            'occupation' => $guardianData['occupation'] ?? null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
    }
}

