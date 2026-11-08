<?php

namespace App\Http\Controllers\DataExchange;

use App\Exceptions\DataExchangeDomainException;
use App\Http\Controllers\Controller;
use App\Services\DataExchange\ExportDatasetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportDatasetController extends Controller
{
    public function __construct(
        protected ExportDatasetService $datasetService
    ) {}

    /**
     * List all export datasets permitted for the user's role.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.read')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to view export datasets.');
        }

        $datasets = $this->datasetService->listDatasets($user);

        return response()->json([
            'data' => $datasets,
        ]);
    }

    /**
     * Get authorized column schema and sensitivity levels for a dataset.
     */
    public function columns(Request $request, string $key): JsonResponse
    {
        $user = $request->user();
        if (!$user->hasRole('ADMIN') && !$user->hasPermission('exports.read')) {
            throw DataExchangeDomainException::forbidden('FORBIDDEN', 'You do not have permission to view export columns.');
        }

        $columns = $this->datasetService->getColumns($key, $user);

        return response()->json([
            'data' => [
                'dataset' => $key,
                'columns' => $columns,
            ],
        ]);
    }
}
