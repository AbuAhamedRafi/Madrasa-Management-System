<?php

namespace App\Http\Controllers\DataExchange;

use App\Http\Controllers\Controller;
use App\Services\DataExchange\ImportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImportTemplateController extends Controller
{
    public function __construct(
        protected ImportTemplateService $templateService
    ) {}

    /**
     * List all active import templates (F02, UI-02).
     */
    public function index(Request $request): JsonResponse
    {
        $templates = $this->templateService->listTemplates($request->query());

        return response()->json([
            'data' => $templates,
        ]);
    }

    /**
     * Download versioned XLSX import template (F02, D08-F02-AT01..AT04).
     */
    public function download(Request $request, string $type): Response
    {
        $version = $request->query('version', '1.0');
        $locale = $request->query('locale', 'bn');

        $result = $this->templateService->downloadTemplate($type, $version, $locale);

        return response($result['content'], 200, [
            'Content-Type' => $result['mime'],
            'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
            'X-Template-Version' => $version,
        ]);
    }

    /**
     * Read template schema and validation rules (F02, D08-F02-AT02).
     */
    public function schema(Request $request, string $type): JsonResponse
    {
        $version = $request->query('version', '1.0');
        $schema = $this->templateService->getSchema($type, $version);

        return response()->json([
            'data' => $schema,
        ]);
    }
}
