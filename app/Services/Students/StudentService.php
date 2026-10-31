<?php

namespace App\Services\Students;

use App\Enums\StudentStatus;
use App\Exceptions\StudentDomainException;
use App\Models\Student;
use App\Repositories\AuditLogRepository;
use App\Repositories\Students\StudentRepository;

class StudentService
{
    public function __construct(
        protected StudentRepository $studentRepository,
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function updateProfile(Student $student, array $data, int $actorId, ?string $ipAddress = null, ?string $requestId = null): Student
    {
        if ($student->status === StudentStatus::ARCHIVED) {
            throw new StudentDomainException(
                'STUDENT_ARCHIVED',
                'Archived student records are immutable and cannot be updated.',
                422
            );
        }

        $beforeState = [
            'name_bn' => $student->name_bn,
            'name_en' => $student->name_en,
            'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
            'gender' => $student->gender,
            'present_address_bn' => $student->present_address_bn,
            'present_address_en' => $student->present_address_en,
            'permanent_address_bn' => $student->permanent_address_bn,
            'permanent_address_en' => $student->permanent_address_en,
        ];

        // Check BRC collision if updated
        if (array_key_exists('birth_registration_number', $data)) {
            $newBrc = $data['birth_registration_number'];
            if (!empty($newBrc)) {
                $normalizedBrc = preg_replace('/[^0-9]/', '', $newBrc);
                $brcHash = hash('sha256', $normalizedBrc);

                $existing = Student::where('birth_registration_hash', $brcHash)
                    ->where('id', '!=', $student->id)
                    ->first();

                if ($existing) {
                    throw new StudentDomainException(
                        'STUDENT_IDENTIFIER_CONFLICT',
                        'A student with this birth registration number already exists.',
                        409,
                        ['birth_registration_number' => ['Duplicate birth registration number.']]
                    );
                }
            }
            $student->birth_registration_number = $newBrc;
        }

        if (isset($data['name_bn'])) $student->name_bn = trim($data['name_bn']);
        if (isset($data['name_en'])) $student->name_en = trim($data['name_en']);
        if (isset($data['date_of_birth'])) $student->date_of_birth = $data['date_of_birth'];
        if (isset($data['gender'])) $student->gender = $data['gender'];
        if (array_key_exists('present_address_bn', $data)) $student->present_address_bn = $data['present_address_bn'];
        if (array_key_exists('present_address_en', $data)) $student->present_address_en = $data['present_address_en'];
        if (array_key_exists('permanent_address_bn', $data)) $student->permanent_address_bn = $data['permanent_address_bn'];
        if (array_key_exists('permanent_address_en', $data)) $student->permanent_address_en = $data['permanent_address_en'];
        if (array_key_exists('profile_photo_path', $data)) $student->profile_photo_path = $data['profile_photo_path'];

        $student->updated_by = $actorId;
        $student->save();

        $afterState = [
            'name_bn' => $student->name_bn,
            'name_en' => $student->name_en,
            'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
            'gender' => $student->gender,
            'present_address_bn' => $student->present_address_bn,
            'present_address_en' => $student->present_address_en,
            'permanent_address_bn' => $student->permanent_address_bn,
            'permanent_address_en' => $student->permanent_address_en,
        ];

        $this->auditLogRepository->log(
            actionCode: 'STUDENT_UPDATED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: $beforeState,
            afterMetadata: $afterState,
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $data['reason'] ?? 'Student profile updated',
            entityType: 'Student',
            entityId: $student->id
        );

        return $student->load(['guardians', 'identifiers', 'documents']);
    }

    public function updateStatus(Student $student, string $newStatusStr, string $reason, int $actorId, ?string $ipAddress = null, ?string $requestId = null): Student
    {
        $newStatus = StudentStatus::tryFrom(strtoupper($newStatusStr));
        if (!$newStatus) {
            throw new StudentDomainException(
                'INVALID_STUDENT_STATUS',
                "Invalid status '{$newStatusStr}'.",
                422,
                ['status' => ["Status must be one of: " . implode(', ', StudentStatus::values())]]
            );
        }

        if (empty(trim($reason))) {
            throw new StudentDomainException(
                'REASON_REQUIRED',
                'A valid reason is required for student status change.',
                422,
                ['reason' => ['Reason is required.']]
            );
        }

        $currentStatus = $student->status;

        if ($currentStatus === StudentStatus::ARCHIVED) {
            throw new StudentDomainException(
                'STUDENT_STATUS_CONFLICT',
                'Archived student status is terminal and cannot be changed.',
                409
            );
        }

        if ($currentStatus === $newStatus) {
            return $student;
        }

        $allowedTransitions = [
            StudentStatus::REGISTERED->value => [
                StudentStatus::ACTIVE->value,
                StudentStatus::WITHDRAWN->value,
                StudentStatus::ARCHIVED->value,
            ],
            StudentStatus::ACTIVE->value => [
                StudentStatus::INACTIVE->value,
                StudentStatus::WITHDRAWN->value,
                StudentStatus::TRANSFERRED->value,
                StudentStatus::GRADUATED->value,
                StudentStatus::ARCHIVED->value,
            ],
            StudentStatus::INACTIVE->value => [
                StudentStatus::ACTIVE->value,
                StudentStatus::WITHDRAWN->value,
                StudentStatus::ARCHIVED->value,
            ],
            StudentStatus::WITHDRAWN->value => [
                StudentStatus::ACTIVE->value,
                StudentStatus::ARCHIVED->value,
            ],
            StudentStatus::TRANSFERRED->value => [
                StudentStatus::ARCHIVED->value,
            ],
            StudentStatus::GRADUATED->value => [
                StudentStatus::ARCHIVED->value,
            ],
        ];

        $validNext = $allowedTransitions[$currentStatus->value] ?? [];
        if (!in_array($newStatus->value, $validNext, true)) {
            throw new StudentDomainException(
                'STUDENT_STATUS_CONFLICT',
                "Invalid status transition from '{$currentStatus->value}' to '{$newStatus->value}'.",
                409
            );
        }

        $student->status = $newStatus;
        $student->updated_by = $actorId;
        $student->save();

        $this->auditLogRepository->log(
            actionCode: 'STUDENT_STATUS_CHANGED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: ['status' => $currentStatus->value],
            afterMetadata: ['status' => $newStatus->value],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $reason,
            entityType: 'Student',
            entityId: $student->id
        );

        return $student->load(['guardians', 'identifiers', 'documents']);
    }
}

