<?php

namespace App\Services\Academics;

use App\Enums\PeriodState;
use App\Models\AcademicCohort;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Department;
use App\Models\EducationAuthority;
use App\Models\OfferingSection;
use App\Models\ProgramClass;

class AcademicQueryService
{
    public function getSummary(?int $programId = null): array
    {
        $programsQuery = AcademicProgram::query();
        $periodsQuery = AcademicPeriod::query();
        $classesQuery = ProgramClass::where('is_active', true);
        $sectionsQuery = OfferingSection::where('is_active', true);

        if ($programId !== null) {
            $programsQuery->where('id', $programId);
            $periodsQuery->where('program_id', $programId);
            $classesQuery->where('program_id', $programId);
            $sectionsQuery->whereHas('offering.period', fn ($q) => $q->where('program_id', $programId));
        }

        return [
            'active_departments_count' => Department::where('is_active', true)->count(),
            'programs_count' => (clone $programsQuery)->count(),
            'active_programs_count' => (clone $programsQuery)->where('state', 'ACTIVE')->count(),
            'open_periods_count' => (clone $periodsQuery)->where('state', 'OPEN')->count(),
            'total_periods_count' => (clone $periodsQuery)->count(),
            'classes_count' => $classesQuery->count(),
            'sections_count' => $sectionsQuery->count(),
            'total_configured_seats' => (int) (clone $sectionsQuery)->sum('capacity'),
        ];
    }

    public function getHierarchy(?int $authorityId = null, ?string $search = null): array
    {
        $authoritiesQuery = EducationAuthority::with([
            'levels.programs' => function ($q) use ($search) {
                $q->with(['department', 'classes', 'tracks', 'cohorts']);
                if ($search !== null && trim($search) !== '') {
                    $term = '%' . strtolower(trim($search)) . '%';
                    $q->where(function ($sub) use ($term) {
                        $sub->whereRaw('LOWER(code) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(name_en) LIKE ?', [$term])
                            ->orWhere('name_bn', 'LIKE', $term);
                    });
                }
            },
        ])->orderBy('sort_order')->orderBy('code');

        if ($authorityId !== null) {
            $authoritiesQuery->where('id', $authorityId);
        }

        $authorities = $authoritiesQuery->get();

        return $authorities->map(function (EducationAuthority $auth) {
            return [
                'id' => $auth->id,
                'code' => $auth->code,
                'name_bn' => $auth->name_bn,
                'name_en' => $auth->name_en,
                'is_active' => $auth->is_active,
                'levels' => $auth->levels->map(function ($lvl) {
                    // Group programs by department for presentation tree
                    $departmentsGrouped = $lvl->programs->groupBy('department_id')->map(function ($progs) {
                        $dept = $progs->first()->department;

                        return [
                            'id' => $dept->id,
                            'code' => $dept->code,
                            'name_bn' => $dept->name_bn,
                            'name_en' => $dept->name_en,
                            'is_active' => $dept->is_active,
                            'programs' => $progs->map(fn (AcademicProgram $p) => [
                                'id' => $p->id,
                                'code' => $p->code,
                                'name_bn' => $p->name_bn,
                                'name_en' => $p->name_en,
                                'scheme' => $p->scheme->value,
                                'duration_years' => $p->duration_years,
                                'state' => $p->state->value,
                                'classes' => $p->classes->map(fn ($c) => [
                                    'id' => $c->id,
                                    'code' => $c->code,
                                    'name_bn' => $c->name_bn,
                                    'name_en' => $c->name_en,
                                    'stage_number' => $c->stage_number,
                                    'is_active' => $c->is_active,
                                ])->values(),
                                'tracks' => $p->tracks->map(fn ($t) => [
                                    'id' => $t->id,
                                    'code' => $t->code,
                                    'name_bn' => $t->name_bn,
                                    'name_en' => $t->name_en,
                                    'is_active' => $t->is_active,
                                ])->values(),
                                'cohorts' => $p->cohorts->map(fn ($ch) => [
                                    'id' => $ch->id,
                                    'label' => $ch->label,
                                    'intake_year' => $ch->intake_year,
                                    'status' => $ch->status->value,
                                ])->values(),
                            ])->values(),
                        ];
                    })->values();

                    return [
                        'id' => $lvl->id,
                        'code' => $lvl->code,
                        'name_bn' => $lvl->name_bn,
                        'name_en' => $lvl->name_en,
                        'is_active' => $lvl->is_active,
                        'departments' => $departmentsGrouped,
                    ];
                })->values(),
            ];
        })->values()->all();
    }

    public function getOptions(
        ?int $programId = null,
        ?int $academicPeriodId = null,
        bool $activeOnly = false
    ): array {
        if ($programId === null && $academicPeriodId !== null) {
            $periodForProgram = AcademicPeriod::find($academicPeriodId);
            $programId = $periodForProgram?->program_id;
        }

        if ($programId === null) {
            $programsQuery = AcademicProgram::orderBy('code');
            if ($activeOnly) {
                $programsQuery->where('state', 'ACTIVE');
            }

            return [
                'programs' => $programsQuery->get()->map(fn (AcademicProgram $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name_bn' => $p->name_bn,
                    'name_en' => $p->name_en,
                    'scheme' => $p->scheme->value,
                    'state' => $p->state->value,
                ])->values(),
                'program' => null,
                'cohorts' => [],
                'periods' => [],
                'offerings' => [],
                'sections' => [],
            ];
        }

        $program = AcademicProgram::find($programId);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $cohortsQuery = AcademicCohort::where('program_id', $program->id)
            ->orderByDesc('intake_year')
            ->orderBy('label');
        if ($activeOnly) {
            $cohortsQuery->where('status', 'ACTIVE');
        }
        $cohorts = $cohortsQuery->get();

        $periodsQuery = AcademicPeriod::where('program_id', $program->id)
            ->orderByDesc('start_date')
            ->orderBy('study_year_number');
        if ($activeOnly) {
            $periodsQuery->where('state', 'OPEN');
        }
        $periods = $periodsQuery->get();

        $periodIds = $academicPeriodId !== null
            ? [$academicPeriodId]
            : $periods->pluck('id')->all();

        $offeringsQuery = AcademicOffering::with(['programClass', 'track'])
            ->whereIn('academic_period_id', $periodIds)
            ->orderBy('program_class_id')
            ->orderBy('academic_track_id');
        if ($activeOnly) {
            $offeringsQuery->where('is_active', true);
        }
        $offerings = $offeringsQuery->get();

        $sectionsQuery = OfferingSection::whereIn('academic_offering_id', $offerings->pluck('id')->all())
            ->orderBy('code');
        if ($activeOnly) {
            $sectionsQuery->where('is_active', true);
        }
        $sections = $sectionsQuery->get();

        return [
            'program' => [
                'id' => $program->id,
                'code' => $program->code,
                'name_bn' => $program->name_bn,
                'name_en' => $program->name_en,
                'scheme' => $program->scheme->value,
                'state' => $program->state->value,
            ],
            'cohorts' => $cohorts->map(fn (AcademicCohort $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'intake_year' => $c->intake_year,
                'status' => $c->status->value,
            ])->values(),
            'periods' => $periods->map(fn (AcademicPeriod $p) => [
                'id' => $p->id,
                'cohort_id' => $p->cohort_id,
                'label' => $p->label,
                'calendar_year' => $p->calendar_year,
                'study_year_number' => $p->study_year_number,
                'state' => $p->state->value,
                'eligible_for_admission' => $p->state === PeriodState::OPEN && $program->state->value === 'ACTIVE',
            ])->values(),
            'offerings' => $offerings->map(fn (AcademicOffering $o) => [
                'id' => $o->id,
                'academic_period_id' => $o->academic_period_id,
                'program_class_id' => $o->program_class_id,
                'academic_track_id' => $o->academic_track_id,
                'class_name_bn' => $o->programClass?->name_bn,
                'class_name_en' => $o->programClass?->name_en,
                'track_name_bn' => $o->track?->name_bn,
                'track_name_en' => $o->track?->name_en,
                'is_active' => $o->is_active,
            ])->values(),
            'sections' => $sections->map(fn (OfferingSection $s) => [
                'id' => $s->id,
                'offering_id' => $s->academic_offering_id,
                'code' => $s->code,
                'name_bn' => $s->name_bn,
                'name_en' => $s->name_en,
                'capacity' => $s->capacity,
                'is_active' => $s->is_active,
            ])->values(),
        ];
    }
}

