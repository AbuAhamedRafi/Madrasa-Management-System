<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\DataExchange\ImportCommitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ImportExecutionController extends Controller
{
    public function __construct(
        protected ImportCommitService $commitService
    ) {}

    /**
     * Resolve and authorize ImportJob.
     */
    protected function resolveAuthorizedJob(Request $request, string $id, string $permission): ImportJob
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission($permission)) {
            throw DataExchangeDomainException::forbidden("You do not have permission '{$permission}'.");
        }

        if ($user->hasRole('ACCOUNTS') && !$user->hasRole('ADMIN')) {
            throw DataExchangeDomainException::forbidden('Accounts staff cannot access student import jobs.');
        }

        $importJob = is_numeric($id)
            ? ImportJob::findOrFail($id)
            : ImportJob::where('job_number', $id)->firstOrFail();

        if ($user->hasRole('OFFICE_STAFF') && !$user->hasRole('ADMIN') && $importJob->requester_id !== $user->id) {
            throw DataExchangeDomainException::forbidden('You can only access import jobs you initiated.');
        }

        return $importJob;
    }

    /**
     * Commit a validated import job (F08).
     */
    public function commit(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.commit');

        $validated = $request->validate([
            'preview_token' => ['required', 'string'],
        ]);

        $result = $this->commitService->commit($job, $validated['preview_token'], $request->user());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Cancel an in-flight import job (F08).
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.cancel');

        $reason = $request->input('reason');
        $result = $this->commitService->cancel($job, $request->user(), $reason);

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Retry failed rows from a completed-with-errors job (F08).
     */
    public function retryFailed(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.commit');

        $result = $this->commitService->retryFailed($job, $request->user());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Download the execution result XLSX file (F08, F09).
     */
    public function downloadResultFile(Request $request, string $id): Response
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.read');

        if ($job->retention_until && $job->retention_until->isPast()) {
            throw DataExchangeDomainException::fileExpired('Result file has expired past its retention period.');
        }

        if (empty($job->result_storage_key) || !Storage::disk('local')->exists($job->result_storage_key)) {
            throw DataExchangeDomainException::notFound('RESULT_FILE_NOT_FOUND', 'Result file is not available for this import job.');
        }

        \App\Models\ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $request->user()?->id,
            'event' => 'RESULT_DOWNLOADED',
            'reason_code' => 'DOWNLOAD',
            'created_at' => now(),
        ]);

        $content = Storage::disk('local')->get($job->result_storage_key);
        $filename = "import_result_{$job->job_number}.xlsx";

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
