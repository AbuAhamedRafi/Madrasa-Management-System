<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\DataExchange\ImportResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportResolutionController extends Controller
{
    public function __construct(
        protected ImportResolutionService $resolutionService
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
     * Submit row duplicate or update decisions (F05, F06).
     */
    public function resolutions(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.review');

        $validated = $request->validate([
            'resolutions' => ['required', 'array', 'min:1'],
            'resolutions.*.row_id' => ['required', 'integer'],
            'resolutions.*.decision' => ['required', 'string'],
            'resolutions.*.target_student_id' => ['nullable', 'string'],
            'resolutions.*.notes' => ['nullable', 'string', 'max:500'],
            'resolutions.*.field_decisions' => ['nullable', 'array'],
        ]);

        $result = $this->resolutionService->saveResolutions($job, $validated['resolutions'], $request->user());

        return response()->json([
            'data' => $result,
        ]);
    }

    /**
     * Trigger revalidation after review decisions (F05, F06).
     */
    public function revalidate(Request $request, string $id): JsonResponse
    {
        $job = $this->resolveAuthorizedJob($request, $id, 'imports.validate');

        $result = $this->resolutionService->revalidate($job, $request->user()->id);

        return response()->json([
            'data' => $result,
        ]);
    }
}
