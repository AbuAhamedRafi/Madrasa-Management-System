<?php

namespace App\Services\Students;

use App\Exceptions\StudentDomainException;
use App\Models\Student;
use App\Models\StudentIdentifier;
use App\Repositories\AuditLogRepository;
use Illuminate\Support\Facades\DB;

class StudentIdentifierService
{
    public function __construct(
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function addIdentifier(Student $student, array $data, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentIdentifier
    {
        return DB::transaction(function () use ($student, $data, $actorId, $ipAddress, $requestId) {
            $namespace = trim($data['namespace']);
            $valRaw = trim($data['value']);
            $valNormalized = strtoupper($valRaw);

            $exists = StudentIdentifier::where('namespace', $namespace)
                ->where('value_normalized', $valNormalized)
                ->exists();

            if ($exists) {
                throw new StudentDomainException(
                    'STUDENT_IDENTIFIER_CONFLICT',
                    "Identifier '{$valRaw}' already registered under namespace '{$namespace}'.",
                    409,
                    ['value' => ['Identifier already in use under this namespace.']]
                );
            }

            $ident = StudentIdentifier::create([
                'student_id' => $student->id,
                'namespace' => $namespace,
                'value_normalized' => $valNormalized,
                'value_raw' => $valRaw,
                'issuer' => $data['issuer'] ?? null,
                'created_by' => $actorId,
            ]);

            $this->auditLogRepository->log(
                actionCode: 'IDENTIFIER_ADDED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: null,
                afterMetadata: [
                    'student_id' => $student->student_id,
                    'namespace' => $namespace,
                    'value_normalized' => $valNormalized,
                ],
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: $data['reason'] ?? 'External identifier added',
                entityType: 'Student',
                entityId: $student->id
            );

            return $ident;
        });
    }

    public function removeIdentifier(Student $student, int $identifierId, int $actorId, ?string $ipAddress = null, ?string $requestId = null): void
    {
        DB::transaction(function () use ($student, $identifierId, $actorId, $ipAddress, $requestId) {
            $ident = StudentIdentifier::where('student_id', $student->id)
                ->where('id', $identifierId)
                ->first();

            if (!$ident) {
                throw new StudentDomainException('IDENTIFIER_NOT_FOUND', 'Identifier not found for this student.', 404);
            }

            $ident->delete();

            $this->auditLogRepository->log(
                actionCode: 'IDENTIFIER_REMOVED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: [
                    'namespace' => $ident->namespace,
                    'value' => $ident->value_raw,
                ],
                afterMetadata: null,
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: 'Identifier removed',
                entityType: 'Student',
                entityId: $student->id
            );
        });
    }
}

