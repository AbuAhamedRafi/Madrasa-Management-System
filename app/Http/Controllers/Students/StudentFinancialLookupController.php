<?php

namespace App\Http\Controllers\Students;

use App\Http\Controllers\Controller;
use App\Repositories\Students\StudentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentFinancialLookupController extends Controller
{
    public function __construct(
        protected StudentRepository $studentRepository
    ) {}

    public function lookup(Request $request): JsonResponse
    {
        $query = (string) $request->query('query', '');
        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);

        $paginator = $this->studentRepository->financialLookup($query, $page, $pageSize);

        $items = collect($paginator->items())->map(fn ($s) => [
            'id' => $s->id,
            'student_id' => $s->student_id,
            'name_bn' => $s->name_bn,
            'name_en' => $s->name_en,
            'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
            ],
        ]);
    }
}

