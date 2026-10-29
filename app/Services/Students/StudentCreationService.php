<?php

namespace App\Services\Students;

use App\Enums\GuardianRelation;
use App\Enums\StudentStatus;
use App\Exceptions\StudentDomainException;
use App\Http\Resources\Students\StudentResource;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\StudentCreateRequest;
use App\Models\StudentGuardian;
use App\Models\StudentIdentifier;
use App\Repositories\AuditLogRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StudentCreationService
{
    public function __construct(
        protected StudentIdAllocator $idAllocator,
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function create(array $data, int $actorId, ?string $idempotencyKey = null, ?string $ipAddress = null, ?string $requestId = null): array
    {
        // 1. Idempotency Check
        $cleanPayload = $data;
        unset($cleanPayload['idempotency_key']);
        $requestHash = hash('sha256', json_encode($cleanPayload));

        if ($idempotencyKey) {
            $existingRequest = StudentCreateRequest::where('actor_id', $actorId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingRequest) {
                if ($existingRequest->request_hash !== $requestHash) {
                    throw new StudentDomainException(
                        'IDEMPOTENCY_PAYLOAD_MISMATCH',
                        'The idempotency key was previously used with a different request payload.',
                        422
                    );
                }

                if ($existingRequest->response_payload) {
                    return [
                        'data' => $existingRequest->response_payload,
                        'is_replay' => true,
                        'http_status' => 200,
                    ];
                }
            }
        }

        // 2. Exact Duplicate Check on Birth Registration Number
        if (!empty($data['birth_registration_number'])) {
            $normalizedBrc = preg_replace('/[^0-9]/', '', $data['birth_registration_number']);
            $brcHash = hash('sha256', $normalizedBrc);

            $existingStudent = Student::where('birth_registration_hash', $brcHash)->first();
            if ($existingStudent) {
                throw new StudentDomainException(
                    'STUDENT_IDENTIFIER_CONFLICT',
                    'A student with this birth registration number already exists.',
                    409,
                    ['birth_registration_number' => ['A student with this birth registration number already exists.']]
                );
            }
        }

        // 3. Normalized Admission Date
        $admissionDate = !empty($data['admission_date'])
            ? Carbon::parse($data['admission_date'], 'Asia/Dhaka')->format('Y-m-d')
            : Carbon::now('Asia/Dhaka')->format('Y-m-d');

        // 4. Concurrency-Safe Transaction
        $student = DB::transaction(function () use ($data, $actorId, $admissionDate, $idempotencyKey, $requestHash, $ipAddress, $requestId) {
            // Allocate permanent Student ID
            $studentId = $this->idAllocator->allocate($admissionDate);

            // Create Student
            $student = new Student();
            $student->student_id = $studentId;
            $student->name_bn = trim($data['name_bn']);
            $student->name_en = trim($data['name_en']);
            $student->date_of_birth = $data['date_of_birth'];
            $student->gender = $data['gender'];
            $student->admission_date = $admissionDate;
            $student->status = StudentStatus::REGISTERED;
            $student->birth_registration_number = $data['birth_registration_number'] ?? null;
            $student->present_address_bn = $data['present_address_bn'] ?? null;
            $student->present_address_en = $data['present_address_en'] ?? null;
            $student->permanent_address_bn = $data['permanent_address_bn'] ?? null;
            $student->permanent_address_en = $data['permanent_address_en'] ?? null;
            $student->profile_photo_path = $data['profile_photo_path'] ?? null;
            $student->created_by = $actorId;
            $student->updated_by = $actorId;
            $student->save();

            // Process Primary Guardian
            $primaryData = $data['primary_guardian'];
            $primaryGuardian = $this->resolveGuardian($primaryData, $actorId);

            $smsConsent = (bool) ($primaryData['sms_consent'] ?? false);
            StudentGuardian::create([
                'student_id' => $student->id,
                'guardian_id' => $primaryGuardian->id,
                'relation' => $primaryData['relation'],
                'is_primary' => true,
                'sms_consent' => $smsConsent,
                'consent_at' => $smsConsent ? now() : null,
                'consent_source' => $primaryData['consent_source'] ?? 'ADMISSION_FORM',
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            // Process Secondary Guardians (if any)
            if (!empty($data['secondary_guardians']) && is_array($data['secondary_guardians'])) {
                foreach ($data['secondary_guardians'] as $secData) {
                    $secGuardian = $this->resolveGuardian($secData, $actorId);
                    $secConsent = (bool) ($secData['sms_consent'] ?? false);

                    StudentGuardian::create([
                        'student_id' => $student->id,
                        'guardian_id' => $secGuardian->id,
                        'relation' => $secData['relation'],
                        'is_primary' => false,
                        'sms_consent' => $secConsent,
                        'consent_at' => $secConsent ? now() : null,
                        'consent_source' => $secData['consent_source'] ?? 'ADMISSION_FORM',
                        'created_by' => $actorId,
                        'updated_by' => $actorId,
                    ]);
                }
            }

            // Process Identifiers (if any)
            if (!empty($data['identifiers']) && is_array($data['identifiers'])) {
                foreach ($data['identifiers'] as $ident) {
                    $namespace = trim($ident['namespace']);
                    $valRaw = trim($ident['value']);
                    $valNormalized = strtoupper($valRaw);

                    $exists = StudentIdentifier::where('namespace', $namespace)
                        ->where('value_normalized', $valNormalized)
                        ->exists();

                    if ($exists) {
                        throw new StudentDomainException(
                            'STUDENT_IDENTIFIER_CONFLICT',
                            "Identifier '{$valRaw}' already exists in namespace '{$namespace}'.",
                            409,
                            ['identifiers' => ["Identifier already registered in namespace {$namespace}."]]
                        );
                    }

                    StudentIdentifier::create([
                        'student_id' => $student->id,
                        'namespace' => $namespace,
                        'value_normalized' => $valNormalized,
                        'value_raw' => $valRaw,
                        'issuer' => $ident['issuer'] ?? null,
                        'created_by' => $actorId,
                    ]);
                }
            }

            // Log Audit
            $this->auditLogRepository->log(
                actionCode: 'STUDENT_REGISTERED',
                actorUserId: $actorId,
                subjectUserId: null,
                beforeMetadata: null,
                afterMetadata: [
                    'student_id' => $student->student_id,
                    'name_bn' => $student->name_bn,
                    'name_en' => $student->name_en,
                    'admission_date' => $admissionDate,
                    'status' => $student->status->value,
                    'primary_guardian_id' => $primaryGuardian->id,
                ],
                ipAddress: $ipAddress,
                requestId: $requestId,
                reason: 'Admission form intake submitted',
                entityType: 'Student',
                entityId: $student->id
            );

            $student->load(['guardians', 'identifiers']);
            $resourceData = (new StudentResource($student))->resolve();

            // Record Idempotency
            if ($idempotencyKey) {
                StudentCreateRequest::updateOrCreate(
                    [
                        'actor_id' => $actorId,
                        'idempotency_key' => $idempotencyKey,
                    ],
                    [
                        'request_hash' => $requestHash,
                        'response_payload' => $resourceData,
                        'student_id' => $student->id,
                    ]
                );
            }

            return $resourceData;
        });

        return [
            'data' => $student,
            'is_replay' => false,
            'http_status' => 201,
        ];
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

