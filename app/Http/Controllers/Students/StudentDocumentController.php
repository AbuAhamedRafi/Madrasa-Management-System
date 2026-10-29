<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Http\Resources\Students\StudentDocumentResource;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Services\Students\StudentDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentDocumentController extends Controller
{
    public function __construct(
        protected StudentDocumentService $studentDocumentService
    ) {}

    public function index(Request $request, int $studentId): JsonResponse
    {
        // Enforce Accounts blocking
        if ($request->user()->roles->contains('key', 'ACCOUNTS') && !$request->user()->hasPermission('students.read')) {
            abort(403, 'Accounts role is unauthorized to view student documents.');
        }

        $student = Student::findOrFail($studentId);
        $docs = StudentDocument::with(['uploader', 'verifier'])
            ->where('student_id', $student->id)
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => StudentDocumentResource::collection($docs),
        ]);
    }

    public function store(Request $request, int $studentId): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'type' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::findOrFail($studentId);

        $doc = $this->studentDocumentService->upload(
            student: $student,
            file: $request->file('file'),
            typeStr: $request->input('type'),
            actorId: $request->user()->id,
            reason: $request->input('reason'),
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new StudentDocumentResource($doc),
        ], 201);
    }

    public function download(Request $request, int $studentId, int $id): BinaryFileResponse
    {
        // Strict Accounts role blocking (AT-D03-34)
        if ($request->user()->roles->contains('key', 'ACCOUNTS') && !$request->user()->hasPermission('students.read')) {
            abort(403, 'Accounts role is unauthorized to stream or download student documents.');
        }

        $student = Student::findOrFail($studentId);

        $fileData = $this->studentDocumentService->getFileForDownload(
            student: $student,
            documentId: $id,
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        $doc = $fileData['document'];

        return response()->download(
            file: $fileData['full_path'],
            name: $doc->file_name,
            headers: [
                'Content-Type' => $doc->mime_type,
                'Content-Disposition' => 'inline; filename="' . addslashes($doc->file_name) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function verify(Request $request, int $studentId, int $id): JsonResponse
    {
        $request->validate([
            'verification_status' => ['required', 'string', 'in:VERIFIED,REJECTED'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $student = Student::findOrFail($studentId);

        $doc = $this->studentDocumentService->verify(
            student: $student,
            documentId: $id,
            statusStr: $request->input('verification_status'),
            reason: $request->input('reason'),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new StudentDocumentResource($doc),
        ]);
    }

    public function destroy(Request $request, int $studentId, int $id): JsonResponse
    {
        $student = Student::findOrFail($studentId);

        $this->studentDocumentService->archive(
            student: $student,
            documentId: $id,
            reason: $request->input('reason'),
            actorId: $request->user()->id,
            ipAddress: $request->ip(),
            requestId: $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => ['success' => true, 'message' => 'Document archived successfully.'],
        ]);
    }
}

