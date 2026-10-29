<?php

namespace App\Repositories\Students;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StudentRepository
{
    public function paginate(array $filters, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        $pageSize = min(max($pageSize, 1), 100);
        $query = Student::with(['guardians', 'identifiers'])
            ->withCount('documents')
            ->orderByDesc('id');

        if (!empty($filters['query'])) {
            $term = trim($filters['query']);
            $query->where(function (Builder $q) use ($term) {
                $q->where('student_id', 'ILIKE', "%{$term}%")
                    ->orWhere('name_bn', 'ILIKE', "%{$term}%")
                    ->orWhere('name_en', 'ILIKE', "%{$term}%")
                    ->orWhereHas('identifiers', function (Builder $iq) use ($term) {
                        $iq->where('value_normalized', strtoupper($term));
                    })
                    ->orWhereHas('guardians', function (Builder $gq) use ($term) {
                        $normalizedPhone = preg_replace('/[^0-9+]/', '', $term);
                        if (!empty($normalizedPhone)) {
                            $gq->where('phone', 'ILIKE', "%{$normalizedPhone}%");
                        }
                    });
            });
        }

        if (!empty($filters['status']) && strtoupper($filters['status']) !== 'ALL') {
            $query->where('status', strtoupper($filters['status']));
        }

        if (!empty($filters['admission_year'])) {
            $query->whereYear('admission_date', (int) $filters['admission_year']);
        }

        if (!empty($filters['gender']) && in_array(strtoupper($filters['gender']), ['MALE', 'FEMALE'])) {
            $query->where('gender', strtoupper($filters['gender']));
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?Student
    {
        return Student::with(['guardians', 'identifiers', 'documents.uploader', 'documents.verifier', 'duplicateReviews'])
            ->find($id);
    }

    public function findByStudentId(string $studentId): ?Student
    {
        return Student::with(['guardians', 'identifiers', 'documents'])
            ->where('student_id', $studentId)
            ->first();
    }

    public function financialLookup(string $query, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        $pageSize = min(max($pageSize, 1), 50);
        $term = trim($query);

        return Student::select(['id', 'student_id', 'name_bn', 'name_en', 'status'])
            ->where(function (Builder $q) use ($term) {
                $q->where('student_id', 'ILIKE', "{$term}%")
                    ->orWhere('name_bn', 'ILIKE', "%{$term}%")
                    ->orWhere('name_en', 'ILIKE', "%{$term}%");
            })
            ->orderBy('student_id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }
}

