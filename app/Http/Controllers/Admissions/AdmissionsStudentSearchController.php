<?php

namespace App\Http\Controllers\Admissions;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionsStudentSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->query('query', ''));
        $limit = min((int) $request->query('limit', 15), 50);

        $query = Student::with([
            'primaryStudentGuardian.guardian',
            'duplicateReviews',
            'programAdmissions.program',
            'enrollments' => function ($q) {
                $q->orderByDesc('id')->with(['period.program', 'section', 'offering.programClass']);
            },
        ]);

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name_bn', 'like', "%{$search}%")
                    ->orWhere('name_en', 'ilike', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%")
                    ->orWhereHas('guardians', function ($gq) use ($search) {
                        $normalizedPhone = preg_replace('/[^0-9]/', '', $search);
                        if (!empty($normalizedPhone)) {
                            $gq->where('phone', 'like', "%{$normalizedPhone}%");
                        }
                    });
            });
        }

        $students = $query->orderByDesc('id')->limit($limit)->get();

        $data = $students->map(function (Student $student) {
            $latestEnrollment = $student->enrollments->first();
            $hasPendingDuplicate = $student->duplicateReviews->where('status', 'PENDING')->isNotEmpty();

            return [
                'id' => $student->id,
                'student_id' => $student->student_id,
                'name_bn' => $student->name_bn,
                'name_en' => $student->name_en,
                'gender' => $student->gender,
                'status' => $student->status->value,
                'is_active' => $student->status === StudentStatus::ACTIVE,
                'has_pending_duplicate' => $hasPendingDuplicate,
                'primary_guardian' => $student->primaryStudentGuardian ? [
                    'name_bn' => $student->primaryStudentGuardian->guardian->name_bn,
                    'name_en' => $student->primaryStudentGuardian->guardian->name_en,
                    'phone' => $student->primaryStudentGuardian->guardian->phone,
                    'relation' => $student->primaryStudentGuardian->relation->value,
                ] : null,
                'active_programs' => $student->programAdmissions
                    ->whereIn('status', ['ACTIVE', 'PENDING_ACTIVATION'])
                    ->map(fn($pa) => [
                        'program_id' => $pa->academic_program_id,
                        'program_code' => $pa->program->code,
                        'program_name_bn' => $pa->program->name_bn,
                        'program_name_en' => $pa->program->name_en,
                        'status' => $pa->status->value,
                    ]),
                'latest_enrollment' => $latestEnrollment ? [
                    'id' => $latestEnrollment->id,
                    'status' => $latestEnrollment->status->value,
                    'period_label' => $latestEnrollment->period?->label,
                    'class_name_bn' => $latestEnrollment->offering?->programClass?->name_bn,
                    'class_name_en' => $latestEnrollment->offering?->programClass?->name_en,
                    'section_code' => $latestEnrollment->section?->code,
                ] : null,
            ];
        });

        return response()->json(['data' => $data]);
    }
}

