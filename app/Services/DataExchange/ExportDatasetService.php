<?php

namespace App\Services\DataExchange;

use App\Enums\ExportDatasetKey;
use App\Exceptions\DataExchangeDomainException;
use App\Models\AcademicCohort;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\OfferingSection;
use App\Models\Payment;
use App\Models\ProgramClass;
use App\Models\PromotionItem;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExportDatasetService
{
    /**
     * Map dataset keys to allowed roles.
     */
    protected array $datasetRoleMatrix = [
        'student_master' => ['ADMIN', 'OFFICE_STAFF'],
        'academic_enrollments' => ['ADMIN', 'OFFICE_STAFF'],
        'student_profile_history' => ['ADMIN', 'OFFICE_STAFF'],
        'promotion_results' => ['ADMIN', 'OFFICE_STAFF'],
        'invoice_ledger' => ['ADMIN', 'ACCOUNTS', 'OFFICE_STAFF'],
        'cash_collections' => ['ADMIN', 'ACCOUNTS'],
        'cashier_reconciliation' => ['ADMIN', 'ACCOUNTS'],
    ];

    /**
     * Get datasets visible and permitted for the actor.
     */
    public function listDatasets(User $actor): array
    {
        $roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);

        $definitions = $this->getDatasetDefinitions();
        $allowedDatasets = [];

        foreach ($definitions as $key => $meta) {
            $allowedRoles = $this->datasetRoleMatrix[$key] ?? [];
            if ($isAdmin || !empty(array_intersect($roleNames, $allowedRoles))) {
                $allowedDatasets[] = [
                    'key' => $key,
                    'name_bn' => $meta['name_bn'],
                    'name_en' => $meta['name_en'],
                    'description_bn' => $meta['description_bn'],
                    'description_en' => $meta['description_en'],
                    'supported_formats' => ['XLSX', 'PDF'],
                    'filters_schema' => $meta['filters_schema'],
                ];
            }
        }

        return $allowedDatasets;
    }

    /**
     * Get permitted columns for a dataset given the actor's permissions.
     */
    public function getColumns(string $datasetKey, User $actor): array
    {
        $this->ensureDatasetAccess($datasetKey, $actor);

        $roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);
        $isAccounts = in_array('ACCOUNTS', $roleNames) && !$isAdmin;

        $definitions = $this->getDatasetDefinitions();
        $dataset = $definitions[$datasetKey] ?? null;
        if (!$dataset) {
            throw DataExchangeDomainException::restrictedDataset("Dataset '{$datasetKey}' is not recognized.");
        }

        $result = [];
        foreach ($dataset['columns'] as $col) {
            $sensitivity = $col['sensitivity'] ?? 'ALLOWED';

            // Check field level restrictions
            $isPermitted = true;
            if ($sensitivity === 'RESTRICTED' && !$isAdmin) {
                $isPermitted = false;
            }

            // Accounts cannot see unmasked guardian phone or full student PII
            if ($isAccounts && in_array($col['key'], ['birth_registration_number', 'guardian_phone', 'present_address', 'permanent_address'])) {
                $isPermitted = false;
                $sensitivity = 'RESTRICTED';
            }

            $result[] = [
                'key' => $col['key'],
                'name_bn' => $col['name_bn'],
                'name_en' => $col['name_en'],
                'type' => $col['type'],
                'sensitivity' => $sensitivity,
                'is_permitted' => $isPermitted,
            ];
        }

        return $result;
    }

    /**
     * Validate requested columns against permission rules.
     */
    public function validateExportRequest(string $datasetKey, array $requestedColumns, array $filters, User $actor): void
    {
        $this->ensureDatasetAccess($datasetKey, $actor);

        $availableColumns = collect($this->getColumns($datasetKey, $actor));
        $permittedKeys = $availableColumns->where('is_permitted', true)->pluck('key')->toArray();

        foreach ($requestedColumns as $col) {
            if (!in_array($col, $permittedKeys)) {
                throw DataExchangeDomainException::restrictedField("Column '{$col}' is restricted or unauthorized for your role in dataset '{$datasetKey}'.");
            }
        }
    }

    /**
     * Dry run count and metadata forecast.
     */
    public function previewCount(string $datasetKey, array $filters, User $actor): int
    {
        $query = $this->buildDatasetQuery($datasetKey, $filters, $actor);
        return $query->count();
    }

    /**
     * Fetch formatted rows and headers for the export.
     *
     * @return array{headers: array<string>, rows: array<array<mixed>>, total_count: int}
     */
    public function fetchExportData(string $datasetKey, array $columns, array $filters, User $actor, string $locale = 'bn'): array
    {
        $this->validateExportRequest($datasetKey, $columns, $filters, $actor);

        $roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);

        $colDefs = collect($this->getColumns($datasetKey, $actor))->keyBy('key');
        $headers = [];
        foreach ($columns as $c) {
            $def = $colDefs->get($c);
            $headers[] = ($locale === 'en') ? ($def['name_en'] ?? $c) : ($def['name_bn'] ?? $c);
        }

        $query = $this->buildDatasetQuery($datasetKey, $filters, $actor);
        $records = $query->get();

        $rows = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as $colKey) {
                $val = $this->extractColumnValue($datasetKey, $record, $colKey, $isAdmin);
                $row[] = $val;
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_count' => count($rows),
        ];
    }

    /**
     * Build query for dataset with applied filters and scope.
     */
    protected function buildDatasetQuery(string $datasetKey, array $filters, User $actor): Builder
    {
        $roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        $isAdmin = in_array('ADMIN', $roleNames);
        $isStaff = in_array('OFFICE_STAFF', $roleNames) && !$isAdmin;

        switch ($datasetKey) {
            case 'student_master':
                $query = Student::query()->with(['primaryStudentGuardian.guardian']);

                if (!empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (!empty($filters['gender'])) {
                    $query->where('gender', $filters['gender']);
                }
                if (!empty($filters['program_id'])) {
                    $query->where('academic_program_id', $filters['program_id']);
                }
                if (!empty($filters['search'])) {
                    $search = trim($filters['search']);
                    $query->where(function ($q) use ($search) {
                        $q->where('student_id', 'like', "%{$search}%")
                            ->orWhere('name_bn', 'like', "%{$search}%")
                            ->orWhere('name_en', 'like', "%{$search}%");
                    });
                }
                // Office staff campus/department scope
                if ($isStaff && !empty($filters['campus_id'])) {
                    $query->where('campus_id', $filters['campus_id']);
                }
                return $query->orderBy('student_id');

            case 'academic_enrollments':
                $query = Enrollment::query()->with([
                    'student',
                    'offering.programClass.program',
                    'offering.period',
                    'period',
                    'section',
                    'programAdmission.cohort',
                ]);

                if (!empty($filters['academic_year'])) {
                    $query->whereHas('period', function ($q) use ($filters) {
                        $q->where('academic_year', $filters['academic_year'])
                            ->orWhere('label', 'like', "%{$filters['academic_year']}%");
                    });
                }
                if (!empty($filters['department_id'])) {
                    $query->whereHas('offering.programClass.program', function ($q) use ($filters) {
                        $q->where('department_id', $filters['department_id']);
                    });
                }
                if (!empty($filters['class_id'])) {
                    $query->whereHas('offering', function ($q) use ($filters) {
                        $q->where('program_class_id', $filters['class_id']);
                    });
                }
                if (!empty($filters['section_id'])) {
                    $query->where('offering_section_id', $filters['section_id']);
                }
                if (!empty($filters['cohort_id'])) {
                    $query->whereHas('programAdmission', function ($q) use ($filters) {
                        $q->where('academic_cohort_id', $filters['cohort_id']);
                    });
                }
                if (!empty($filters['period_id'])) {
                    $query->where('academic_period_id', $filters['period_id']);
                }
                if (!empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                return $query->orderBy('id');

            case 'student_profile_history':
                $query = AuditLog::query()->where('entity_type', 'Student');
                if (!empty($filters['student_id'])) {
                    $student = Student::where('student_id', $filters['student_id'])->orWhere('id', $filters['student_id'])->first();
                    if ($student) {
                        $query->where('entity_id', (string)$student->id);
                    }
                }
                return $query->orderByDesc('created_at');

            case 'promotion_results':
                $query = PromotionItem::query()->with(['batch', 'student', 'fromClass', 'toClass', 'fromPeriod', 'toPeriod']);
                if (!empty($filters['batch_id'])) {
                    $query->where('batch_id', $filters['batch_id']);
                }
                if (!empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                return $query->orderByDesc('id');

            case 'invoice_ledger':
                $query = Invoice::query()->with(['student', 'period']);
                if (!empty($filters['academic_period_id'])) {
                    $query->where('academic_period_id', $filters['academic_period_id']);
                }
                if (!empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (!empty($filters['date_from'])) {
                    $query->whereDate('issued_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $query->whereDate('issued_at', '<=', $filters['date_to']);
                }
                return $query->orderBy('invoice_number');

            case 'cash_collections':
                // Strict F11 constraint: ONLY POSTED payments are counted. Unpaid payment slips or pending payments are never counted as cash.
                $query = Payment::query()->where('status', 'POSTED')->with(['student', 'cashierShift.cashier']);
                if (!empty($filters['payment_method'])) {
                    $query->where('payment_method', $filters['payment_method']);
                }
                if (!empty($filters['cashier_id'])) {
                    $query->where('collected_by', $filters['cashier_id']);
                }
                if (!empty($filters['date_from'])) {
                    $query->whereDate('collected_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $query->whereDate('collected_at', '<=', $filters['date_to']);
                }
                return $query->orderByDesc('collected_at');

            case 'cashier_reconciliation':
                $query = CashierShift::query()->with(['cashier']);
                if (!empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (!empty($filters['date_from'])) {
                    $query->whereDate('opened_at', '>=', $filters['date_from']);
                }
                if (!empty($filters['date_to'])) {
                    $query->whereDate('opened_at', '<=', $filters['date_to']);
                }
                return $query->orderByDesc('opened_at');

            default:
                throw DataExchangeDomainException::restrictedDataset("Dataset '{$datasetKey}' query builder not found.");
        }
    }

    /**
     * Extract formatted column values.
     */
    protected function extractColumnValue(string $datasetKey, mixed $record, string $colKey, bool $isAdmin): mixed
    {
        switch ($datasetKey) {
            case 'student_master':
                /** @var Student $record */
                return match ($colKey) {
                    'student_id' => $record->student_id,
                    'name_bn' => $record->name_bn,
                    'name_en' => $record->name_en,
                    'gender' => $record->gender?->value ?? $record->gender,
                    'date_of_birth' => $record->date_of_birth?->format('Y-m-d') ?? (is_string($record->date_of_birth) ? $record->date_of_birth : ''),
                    'blood_group' => $record->blood_group,
                    'status' => $record->status?->value ?? $record->status,
                    'birth_registration_number' => $isAdmin ? $record->birth_registration_number : '***',
                    'primary_guardian_name' => $record->primaryStudentGuardian?->guardian?->name_bn ?? $record->primaryStudentGuardian?->guardian?->name_en ?? '',
                    'primary_guardian_relation' => $record->primaryStudentGuardian?->relation?->value ?? ($record->primaryStudentGuardian?->relation ?? ''),
                    'guardian_phone' => $isAdmin ? ($record->primaryStudentGuardian?->guardian?->phone ?? '') : $this->maskPhone($record->primaryStudentGuardian?->guardian?->phone ?? ''),
                    'guardian_phone_masked' => $this->maskPhone($record->primaryStudentGuardian?->guardian?->phone ?? ''),
                    'present_address' => $isAdmin ? ($record->present_address ?? '') : '***',
                    'permanent_address' => $isAdmin ? ($record->permanent_address ?? '') : '***',
                    default => $record->{$colKey} ?? '',
                };

            case 'academic_enrollments':
                /** @var Enrollment $record */
                return match ($colKey) {
                    'student_id' => $record->student?->student_id ?? '',
                    'student_name' => $record->student?->name_bn ?? $record->student?->name_en ?? '',
                    'academic_year' => $record->period?->academic_year ?? $record->offering?->period?->academic_year ?? '',
                    'program' => $record->offering?->programClass?->program?->name_bn ?? $record->offering?->programClass?->program?->name_en ?? '',
                    'cohort' => $record->programAdmission?->cohort?->name ?? $record->programAdmission?->cohort?->code ?? '',
                    'period' => $record->period?->label ?? $record->offering?->period?->label ?? '',
                    'class' => $record->offering?->programClass?->name_bn ?? $record->offering?->programClass?->name_en ?? '',
                    'section' => $record->section?->name_bn ?? $record->section?->section_code ?? '',
                    'roll_number' => $record->roll_number ?? '',
                    'status' => $record->status?->value ?? $record->status,
                    'enrolled_at' => $record->created_at?->format('Y-m-d H:i') ?? '',
                    default => $record->{$colKey} ?? '',
                };

            case 'student_profile_history':
                /** @var AuditLog $record */
                return match ($colKey) {
                    'student_id' => $record->entity_id,
                    'event_type' => $record->action_code,
                    'description' => $record->reason ?? '',
                    'performed_by' => $record->actor_user_id ? "User #{$record->actor_user_id}" : 'System',
                    'occurred_at' => $record->created_at?->format('Y-m-d H:i:s') ?? '',
                    default => $record->{$colKey} ?? '',
                };

            case 'promotion_results':
                /** @var PromotionItem $record */
                return match ($colKey) {
                    'student_id' => $record->student?->student_id ?? '',
                    'student_name' => $record->student?->name_bn ?? $record->student?->name_en ?? '',
                    'from_class' => $record->fromClass?->name_bn ?? '',
                    'to_class' => $record->toClass?->name_bn ?? '',
                    'from_period' => $record->fromPeriod?->label ?? '',
                    'to_period' => $record->toPeriod?->label ?? '',
                    'status' => $record->status?->value ?? $record->status,
                    'decided_at' => $record->created_at?->format('Y-m-d H:i') ?? '',
                    default => $record->{$colKey} ?? '',
                };

            case 'invoice_ledger':
                /** @var Invoice $record */
                $paid = DB::table('payment_allocations')
                    ->where('invoice_id', $record->id)
                    ->where('direction', 'CREDIT')
                    ->sum('allocated_amount');
                $reversed = DB::table('payment_allocations')
                    ->where('invoice_id', $record->id)
                    ->where('direction', 'DEBIT')
                    ->sum('allocated_amount');
                $netPaid = max(0, (float)($paid - $reversed));
                $balance = max(0, (float)$record->net_amount - $netPaid);

                return match ($colKey) {
                    'invoice_number' => $record->invoice_number,
                    'student_id' => $record->student?->student_id ?? '',
                    'student_name' => $record->student?->name_bn ?? $record->student?->name_en ?? '',
                    'period' => $record->period?->label ?? '',
                    'total_amount' => (float)$record->gross_amount,
                    'waiver_amount' => (float)$record->adjustment_amount,
                    'net_amount' => (float)$record->net_amount,
                    'paid_amount' => $netPaid,
                    'balance_amount' => $balance,
                    'status' => $record->status?->value ?? $record->status,
                    'due_date' => $record->due_at?->format('Y-m-d') ?? '',
                    'created_at' => $record->created_at?->format('Y-m-d H:i') ?? '',
                    default => $record->{$colKey} ?? '',
                };

            case 'cash_collections':
                /** @var Payment $record */
                return match ($colKey) {
                    'receipt_number' => $record->payment_reference,
                    'payment_method' => $record->payment_method,
                    'student_id' => $record->student?->student_id ?? '',
                    'student_name' => $record->student?->name_bn ?? $record->student?->name_en ?? '',
                    'amount' => (float)$record->amount_received,
                    'status' => $record->status?->value ?? $record->status,
                    'shift_id' => $record->cashier_shift_id ?? '',
                    'collected_by' => $record->collected_by ? "User #{$record->collected_by}" : '',
                    'collected_at' => $record->collected_at?->format('Y-m-d H:i:s') ?? '',
                    'notes' => $record->remarks ?? '',
                    default => $record->{$colKey} ?? '',
                };

            case 'cashier_reconciliation':
                /** @var CashierShift $record */
                $totalCollected = DB::table('payments')
                    ->where('cashier_shift_id', $record->id)
                    ->where('status', 'POSTED')
                    ->sum('amount_received');
                $totalReversed = DB::table('payment_reversals')
                    ->where('cashier_shift_id', $record->id)
                    ->where('status', 'COMPLETED')
                    ->sum('amount_reversed');
                $netCash = (float)($totalCollected - $totalReversed);

                return match ($colKey) {
                    'shift_id' => $record->shift_number,
                    'cashier_name' => $record->cashier?->name ?? "User #{$record->cashier_user_id}",
                    'started_at' => $record->opened_at?->format('Y-m-d H:i:s') ?? '',
                    'ended_at' => $record->closed_at?->format('Y-m-d H:i:s') ?? '',
                    'opening_balance' => (float)$record->opening_float_amount,
                    'total_collected' => (float)$totalCollected,
                    'total_reversed' => (float)$totalReversed,
                    'net_cash' => $netCash,
                    'closing_balance' => (float)$record->counted_cash_amount,
                    'discrepancy' => (float)$record->variance_amount,
                    'status' => $record->status?->value ?? $record->status,
                    default => $record->{$colKey} ?? '',
                };

            default:
                return $record->{$colKey} ?? '';
        }
    }

    /**
     * Ensure actor has access to the requested dataset.
     */
    protected function ensureDatasetAccess(string $datasetKey, User $actor): void
    {
        $roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        if (in_array('ADMIN', $roleNames)) {
            return;
        }

        $allowedRoles = $this->datasetRoleMatrix[$datasetKey] ?? [];
        if (empty(array_intersect($roleNames, $allowedRoles))) {
            throw DataExchangeDomainException::forbidden('RESTRICTED_DATASET', "Role cannot access dataset '{$datasetKey}'.");
        }
    }

    /**
     * Mask phone number e.g. 01712345678 -> 017*****678
     */
    protected function maskPhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        $len = strlen($phone);
        if ($len <= 5) {
            return '***';
        }
        $prefix = substr($phone, 0, 3);
        $suffix = substr($phone, -3);
        return $prefix . str_repeat('*', max(3, $len - 6)) . $suffix;
    }

    /**
     * Dataset schemas definition.
     */
    protected function getDatasetDefinitions(): array
    {
        return [
            'student_master' => [
                'name_bn' => 'শিক্ষার্থী মাস্টার ডাটা',
                'name_en' => 'Student Master Dataset',
                'description_bn' => 'সকল নিবন্ধিত শিক্ষার্থীর পূর্ণাঙ্গ প্রোফাইল ও অভিভাবক সংক্রান্ত তথ্য।',
                'description_en' => 'Comprehensive directory of active and registered students with primary guardian info.',
                'filters_schema' => ['status', 'gender', 'program_id', 'campus_id', 'search'],
                'columns' => [
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'name_bn', 'name_bn' => 'নাম (বাংলা)', 'name_en' => 'Name (Bengali)', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'name_en', 'name_bn' => 'নাম (ইংরেজি)', 'name_en' => 'Name (English)', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'gender', 'name_bn' => 'লিঙ্গ', 'name_en' => 'Gender', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'date_of_birth', 'name_bn' => 'জন্ম তারিখ', 'name_en' => 'Date of Birth', 'type' => 'DATE', 'sensitivity' => 'SENSITIVE'],
                    ['key' => 'blood_group', 'name_bn' => 'রক্তের গ্রুপ', 'name_en' => 'Blood Group', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'birth_registration_number', 'name_bn' => 'জন্ম নিবন্ধন নম্বর', 'name_en' => 'Birth Reg No', 'type' => 'STRING', 'sensitivity' => 'RESTRICTED'],
                    ['key' => 'primary_guardian_name', 'name_bn' => 'অভিভাবকের নাম', 'name_en' => 'Guardian Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'primary_guardian_relation', 'name_bn' => 'সম্পর্ক', 'name_en' => 'Relation', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'guardian_phone', 'name_bn' => 'অভিভাবকের ফোন', 'name_en' => 'Guardian Phone', 'type' => 'STRING', 'sensitivity' => 'SENSITIVE'],
                    ['key' => 'guardian_phone_masked', 'name_bn' => 'ফোন (মাস্কড)', 'name_en' => 'Guardian Phone (Masked)', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'present_address', 'name_bn' => 'বর্তমান ঠিকানা', 'name_en' => 'Present Address', 'type' => 'STRING', 'sensitivity' => 'SENSITIVE'],
                    ['key' => 'permanent_address', 'name_bn' => 'স্থায়ী ঠিকানা', 'name_en' => 'Permanent Address', 'type' => 'STRING', 'sensitivity' => 'SENSITIVE'],
                ],
            ],
            'academic_enrollments' => [
                'name_bn' => 'একাডেমিক ভর্তি তালিকা',
                'name_en' => 'Academic Enrollments',
                'description_bn' => 'শিক্ষাবর্ষ, বিভাগ ও শ্রেণিভিত্তিক সক্রিয় ভর্তি রেকর্ড।',
                'description_en' => 'Active enrollment records grouped by session, program, cohort and section.',
                'filters_schema' => ['academic_year', 'department_id', 'program_id', 'period_id', 'class_id', 'section_id', 'cohort_id', 'status'],
                'columns' => [
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'academic_year', 'name_bn' => 'শিক্ষাবর্ষ', 'name_en' => 'Academic Year', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'program', 'name_bn' => 'প্রোগ্রাম', 'name_en' => 'Program', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'cohort', 'name_bn' => 'কোহোর্ট', 'name_en' => 'Cohort', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'period', 'name_bn' => 'পিরিয়ড', 'name_en' => 'Academic Period', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'class', 'name_bn' => 'শ্রেণি', 'name_en' => 'Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'section', 'name_bn' => 'শাখা', 'name_en' => 'Section', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'roll_number', 'name_bn' => 'রোল নম্বর', 'name_en' => 'Roll Number', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Enrollment Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'enrolled_at', 'name_bn' => 'ভর্তির তারিখ', 'name_en' => 'Enrolled At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                ],
            ],
            'student_profile_history' => [
                'name_bn' => 'প্রোফাইল পরিবর্তন ইতিহাস',
                'name_en' => 'Student Profile History',
                'description_bn' => 'শিক্ষার্থী প্রোফাইল পরিবর্তনের নিরীক্ষা ট্রেইল।',
                'description_en' => 'Audit logs and timeline events for student profile mutations.',
                'filters_schema' => ['student_id', 'date_from', 'date_to'],
                'columns' => [
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'event_type', 'name_bn' => 'ইভেন্ট ধরন', 'name_en' => 'Event Type', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'description', 'name_bn' => 'বিবরণ', 'name_en' => 'Description', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'performed_by', 'name_bn' => 'সম্পাদনকারী', 'name_en' => 'Performed By', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'occurred_at', 'name_bn' => 'সময়', 'name_en' => 'Occurred At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                ],
            ],
            'promotion_results' => [
                'name_bn' => 'উত্তীর্ণ ও প্রমোশন ফলাফল',
                'name_en' => 'Promotion Results',
                'description_bn' => 'এক শ্রেণি থেকে পরবর্তী শ্রেণিতে উত্তীর্ণ ফলাফল।',
                'description_en' => 'Promotion decision records across academic periods.',
                'filters_schema' => ['batch_id', 'status'],
                'columns' => [
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'from_class', 'name_bn' => 'পূর্ববর্তী শ্রেণি', 'name_en' => 'From Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'to_class', 'name_bn' => 'পরবর্তী শ্রেণি', 'name_en' => 'To Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'from_period', 'name_bn' => 'পূর্ববর্তী পিরিয়ড', 'name_en' => 'From Period', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'to_period', 'name_bn' => 'পরবর্তী পিরিয়ড', 'name_en' => 'To Period', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'decided_at', 'name_bn' => 'সিদ্ধান্তের তারিখ', 'name_en' => 'Decided At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                ],
            ],
            'invoice_ledger' => [
                'name_bn' => 'ইনভয়েস ও ফি লেজার',
                'name_en' => 'Invoice Ledger',
                'description_bn' => 'শিক্ষার্থীদের মোট ধার্য ফি, প্রদত্ত পরিমাণ ও বর্তমান বকেয়া।',
                'description_en' => 'Authoritative student fee billing ledger reconciling invoices, waivers, and collections.',
                'filters_schema' => ['academic_period_id', 'status', 'date_from', 'date_to'],
                'columns' => [
                    ['key' => 'invoice_number', 'name_bn' => 'ইনভয়েস নম্বর', 'name_en' => 'Invoice No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'period', 'name_bn' => 'পিরিয়ড', 'name_en' => 'Period', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'total_amount', 'name_bn' => 'ধার্য ফি (টাকা)', 'name_en' => 'Total Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'waiver_amount', 'name_bn' => 'ছাড়/মওকুফ', 'name_en' => 'Waiver Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'net_amount', 'name_bn' => 'প্রদেয় নিট ফি', 'name_en' => 'Net Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'paid_amount', 'name_bn' => 'আদায়কৃত ফি', 'name_en' => 'Paid Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'balance_amount', 'name_bn' => 'বকেয়া ফি', 'name_en' => 'Balance Due (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'due_date', 'name_bn' => 'পরিশোধের শেষ তারিখ', 'name_en' => 'Due Date', 'type' => 'DATE', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'created_at', 'name_bn' => 'ইস্যুর তারিখ', 'name_en' => 'Issued At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                ],
            ],
            'cash_collections' => [
                'name_bn' => 'ক্যাশ কালেকশন রিপোর্ট',
                'name_en' => 'Cash Collections Report',
                'description_bn' => 'কাউন্টার ও ক্যাশিয়ারভিত্তিক প্রকৃত সংগৃহীত অর্থ (শুধুমাত্র পোস্টকৃত রসিদ)।',
                'description_en' => 'Actual posted cash collections by counter and shift. Excludes unpaid slips.',
                'filters_schema' => ['payment_method', 'cashier_id', 'date_from', 'date_to'],
                'columns' => [
                    ['key' => 'receipt_number', 'name_bn' => 'রসিদ নম্বর', 'name_en' => 'Receipt No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'payment_method', 'name_bn' => 'পদ্ধতি', 'name_en' => 'Method', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'amount', 'name_bn' => 'সংগৃহীত অর্থ (টাকা)', 'name_en' => 'Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'shift_id', 'name_bn' => 'শিফট', 'name_en' => 'Shift', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'collected_by', 'name_bn' => 'আদায়কারী', 'name_en' => 'Collected By', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'collected_at', 'name_bn' => 'সময়', 'name_en' => 'Collected At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'notes', 'name_bn' => 'মন্তব্য', 'name_en' => 'Remarks', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                ],
            ],
            'cashier_reconciliation' => [
                'name_bn' => 'ক্যাশিয়ার সমন্বয় বিবরণী',
                'name_en' => 'Cashier Reconciliation',
                'description_bn' => 'প্রতিটি শিফটের মোট আদায়, রিভার্সাল ও নগদ ক্যাশ মেলানো।',
                'description_en' => 'Shift-level cash summary, reversals, counted cash and variance reconciliation.',
                'filters_schema' => ['status', 'date_from', 'date_to'],
                'columns' => [
                    ['key' => 'shift_id', 'name_bn' => 'শিফট নম্বর', 'name_en' => 'Shift No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'cashier_name', 'name_bn' => 'ক্যাশিয়ার', 'name_en' => 'Cashier Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'started_at', 'name_bn' => 'শুরু', 'name_en' => 'Opened At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'ended_at', 'name_bn' => 'শেষ', 'name_en' => 'Closed At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'opening_balance', 'name_bn' => 'প্রারম্ভিক ব্যালেন্স (টাকা)', 'name_en' => 'Opening Float', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'total_collected', 'name_bn' => 'মোট আদায় (টাকা)', 'name_en' => 'Total Collected', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'total_reversed', 'name_bn' => 'রিভার্সাল (টাকা)', 'name_en' => 'Total Reversed', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'net_cash', 'name_bn' => 'নিট ক্যাশ (টাকা)', 'name_en' => 'Net Cash', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'closing_balance', 'name_bn' => 'গণনাকৃত ক্যাশ', 'name_en' => 'Counted Cash', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'discrepancy', 'name_bn' => 'পার্থক্য', 'name_en' => 'Variance', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                    ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                ],
            ],
        ];
    }
}
