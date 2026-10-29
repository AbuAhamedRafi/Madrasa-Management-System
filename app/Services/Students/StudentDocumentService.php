<?php

namespace App\Services\Students;

use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use App\Exceptions\StudentDomainException;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Repositories\AuditLogRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentDocumentService
{
    public function __construct(
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function upload(Student $student, UploadedFile $file, string $typeStr, int $actorId, ?string $reason = null, ?string $ipAddress = null, ?string $requestId = null): StudentDocument
    {
        $type = DocumentType::tryFrom(strtoupper(trim($typeStr)));
        if (!$type) {
            throw new StudentDomainException(
                'INVALID_DOCUMENT_TYPE',
                "Document type '{$typeStr}' is not supported.",
                422,
                ['type' => ["Supported types: " . implode(', ', DocumentType::values())]]
            );
        }

        // 1. Size Check (Max 5MB)
        $maxBytes = 5 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new StudentDomainException(
                'DOCUMENT_SIZE_EXCEEDED',
                'Document size exceeds the 5 MB limit.',
                422,
                ['file' => ['File size exceeds the 5 MB limit.']]
            );
        }

        // 2. Extension & MIME Check (Strictly PDF documents only)
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'pdf') {
            throw new StudentDomainException(
                'UNSAFE_DOCUMENT_TYPE',
                'Only PDF files are accepted for student documents.',
                422,
                ['file' => ['Only PDF files are accepted for student documents.']]
            );
        }

        $mime = $file->getMimeType();
        if ($mime !== 'application/pdf') {
            throw new StudentDomainException(
                'UNSAFE_DOCUMENT_TYPE',
                'Invalid MIME type. Only PDF documents are accepted.',
                422,
                ['file' => ['Invalid MIME type. Only PDF documents are accepted.']]
            );
        }

        // 3. Binary Magic Bytes Inspection (Must start with %PDF-)
        $contents = file_get_contents($file->getRealPath(), false, null, 0, 16);
        if (!str_starts_with($contents, "%PDF-")) {
            throw new StudentDomainException(
                'UNSAFE_DOCUMENT_TYPE',
                'Unsafe file payload: file content signature does not match PDF format.',
                422,
                ['file' => ['File content signature does not match PDF format.']]
            );
        }

        // 4. Safe Private Storage
        $sha256 = hash_file('sha256', $file->getRealPath());
        $filename = Str::uuid() . '.' . $ext;
        $dir = 'student_documents/' . $student->id;

        // Ensure directory exists with read/write permissions
        $absoluteDir = storage_path('app/private/' . $dir);
        if (!file_exists($absoluteDir)) {
            @mkdir($absoluteDir, 0777, true);
            @chmod($absoluteDir, 0777);
        }

        $storedPath = $file->storeAs($dir, $filename, 'local');

        $doc = StudentDocument::create([
            'student_id' => $student->id,
            'type' => $type,
            'file_name' => $file->getClientOriginalName(),
            'storage_key' => $storedPath,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'verification_status' => DocumentVerificationStatus::PENDING,
            'uploaded_by' => $actorId,
        ]);

        $this->auditLogRepository->log(
            actionCode: 'DOCUMENT_UPLOADED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: null,
            afterMetadata: [
                'document_id' => $doc->id,
                'type' => $doc->type->value,
                'file_name' => $doc->file_name,
                'size_bytes' => $doc->size_bytes,
                'sha256' => $doc->sha256,
            ],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $reason ?? 'Student document uploaded',
            entityType: 'StudentDocument',
            entityId: $doc->id
        );

        return $doc->load('uploader');
    }

    public function getFileForDownload(Student $student, int $documentId, int $actorId, ?string $ipAddress = null, ?string $requestId = null): array
    {
        $doc = StudentDocument::where('student_id', $student->id)
            ->where('id', $documentId)
            ->first();

        if (!$doc) {
            throw new StudentDomainException('DOCUMENT_NOT_FOUND', 'Document not found for this student.', 404);
        }

        if ($doc->archived_at) {
            throw new StudentDomainException('DOCUMENT_ARCHIVED', 'This document has been archived.', 410);
        }

        if (!Storage::disk('local')->exists($doc->storage_key)) {
            throw new StudentDomainException('FILE_MISSING_ON_DISK', 'Document file is missing from private storage.', 404);
        }

        $this->auditLogRepository->log(
            actionCode: 'DOCUMENT_DOWNLOADED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: null,
            afterMetadata: [
                'document_id' => $doc->id,
                'student_id' => $student->student_id,
                'file_name' => $doc->file_name,
            ],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: 'Authenticated document stream download',
            entityType: 'StudentDocument',
            entityId: $doc->id
        );

        return [
            'document' => $doc,
            'full_path' => Storage::disk('local')->path($doc->storage_key),
        ];
    }

    public function verify(Student $student, int $documentId, string $statusStr, string $reason, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentDocument
    {
        $status = DocumentVerificationStatus::tryFrom(strtoupper(trim($statusStr)));
        if (!$status || $status === DocumentVerificationStatus::PENDING) {
            throw new StudentDomainException(
                'INVALID_VERIFICATION_STATUS',
                "Status must be either 'VERIFIED' or 'REJECTED'.",
                422
            );
        }

        if (empty(trim($reason))) {
            throw new StudentDomainException(
                'REASON_REQUIRED',
                'A verification reason is required.',
                422,
                ['reason' => ['Verification reason is required.']]
            );
        }

        $doc = StudentDocument::where('student_id', $student->id)
            ->where('id', $documentId)
            ->first();

        if (!$doc) {
            throw new StudentDomainException('DOCUMENT_NOT_FOUND', 'Document not found.', 404);
        }

        $prevStatus = $doc->verification_status;
        $doc->verification_status = $status;
        $doc->verified_by = $actorId;
        $doc->verification_reason = trim($reason);
        $doc->save();

        $this->auditLogRepository->log(
            actionCode: 'DOCUMENT_VERIFIED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: ['verification_status' => $prevStatus->value],
            afterMetadata: [
                'document_id' => $doc->id,
                'verification_status' => $status->value,
                'reason' => $reason,
            ],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $reason,
            entityType: 'StudentDocument',
            entityId: $doc->id
        );

        return $doc->load(['uploader', 'verifier']);
    }

    public function archive(Student $student, int $documentId, ?string $reason, int $actorId, ?string $ipAddress = null, ?string $requestId = null): StudentDocument
    {
        $doc = StudentDocument::where('student_id', $student->id)
            ->where('id', $documentId)
            ->first();

        if (!$doc) {
            throw new StudentDomainException('DOCUMENT_NOT_FOUND', 'Document not found.', 404);
        }

        $doc->archived_at = now();
        $doc->save();

        $this->auditLogRepository->log(
            actionCode: 'DOCUMENT_ARCHIVED',
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: ['archived_at' => null],
            afterMetadata: ['archived_at' => $doc->archived_at->toIso8601String()],
            ipAddress: $ipAddress,
            requestId: $requestId,
            reason: $reason ?? 'Document archived',
            entityType: 'StudentDocument',
            entityId: $doc->id
        );

        return $doc;
    }
}

