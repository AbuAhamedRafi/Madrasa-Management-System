<?php

namespace App\Services\Billing;

use App\Enums\FeeCadence;
use App\Enums\FeeStructureStatus;
use App\Exceptions\BillingDomainException;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\FeeStructureItem;
use App\Models\ProgramClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class FeeStructureService
{
    public function listStructures(array $filters = []): LengthAwarePaginator
    {
        $query = FeeStructure::with([
            'program',
            'period',
            'offering.programClass',
            'programClass',
            'items.feeCategory',
        ]);

        if (!empty($filters['program_id'])) {
            $query->where('program_id', (int) $filters['program_id']);
        }
        if (!empty($filters['academic_period_id'])) {
            $query->where('academic_period_id', (int) $filters['academic_period_id']);
        }
        if (!empty($filters['academic_offering_id'])) {
            $query->where('academic_offering_id', (int) $filters['academic_offering_id']);
        }
        if (!empty($filters['program_class_id'])) {
            $query->where('program_class_id', (int) $filters['program_class_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['effective_on'])) {
            $date = $filters['effective_on'];
            $query->where('effective_from', '<=', $date)
                  ->where(function ($q) use ($date) {
                      $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
                  });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function getStructure(int $id): FeeStructure
    {
        $structure = FeeStructure::with([
            'program',
            'period',
            'offering.programClass',
            'programClass',
            'items.feeCategory',
            'creator',
            'publisher',
        ])->find($id);

        if (!$structure) {
            throw BillingDomainException::notFound('FEE_STRUCTURE_NOT_FOUND', "Fee structure #{$id} not found.");
        }

        return $structure;
    }

    public function createDraft(array $data, int $actorId): FeeStructure
    {
        $this->validateScopeHierarchy($data);
        $this->validateDates($data['effective_from'], $data['effective_to'] ?? null);

        $resolvedItems = $this->validateAndPrepareItems($data['items'] ?? []);

        return DB::transaction(function () use ($data, $resolvedItems, $actorId) {
            $programId = (int) $data['program_id'];
            $periodId = !empty($data['academic_period_id']) ? (int) $data['academic_period_id'] : null;
            $offeringId = !empty($data['academic_offering_id']) ? (int) $data['academic_offering_id'] : null;
            $classId = !empty($data['program_class_id']) ? (int) $data['program_class_id'] : null;

            if ($offeringId && !$classId) {
                $offering = AcademicOffering::find($offeringId);
                $classId = $offering?->program_class_id;
            }

            $maxVersion = FeeStructure::where('program_id', $programId)
                ->where('academic_period_id', $periodId)
                ->where('academic_offering_id', $offeringId)
                ->where('program_class_id', $classId)
                ->max('version') ?? 0;

            $structure = FeeStructure::create([
                'program_id' => $programId,
                'academic_period_id' => $periodId,
                'academic_offering_id' => $offeringId,
                'program_class_id' => $classId,
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'version' => $maxVersion + 1,
                'status' => FeeStructureStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
                'lock_version' => 1,
                'created_by' => $actorId,
            ]);

            foreach ($resolvedItems as $idx => $itemData) {
                FeeStructureItem::create([
                    'fee_structure_id' => $structure->id,
                    'fee_category_id' => $itemData['fee_category_id'],
                    'cadence' => $itemData['cadence'],
                    'amount' => $itemData['amount'],
                    'admission_required' => $itemData['admission_required'],
                    'due_rule' => $itemData['due_rule'],
                    'sort_order' => $itemData['sort_order'] ?? ($idx + 1),
                ]);
            }

            return $this->getStructure($structure->id);
        });
    }

    public function updateDraft(int $id, array $data, int $actorId): FeeStructure
    {
        $structure = FeeStructure::find($id);
        if (!$structure) {
            throw BillingDomainException::notFound('FEE_STRUCTURE_NOT_FOUND', "Fee structure #{$id} not found.");
        }

        if ($structure->status !== FeeStructureStatus::DRAFT) {
            throw BillingDomainException::conflict(
                'FEE_STRUCTURE_IMMUTABLE',
                'Published or archived fee structures cannot be modified in place. Clone or create a new draft version.'
            );
        }

        if (isset($data['lock_version']) && (int) $data['lock_version'] !== $structure->lock_version) {
            throw BillingDomainException::staleVersion($structure->lock_version, (int) $data['lock_version']);
        }

        $mergedScope = [
            'program_id' => $data['program_id'] ?? $structure->program_id,
            'academic_period_id' => array_key_exists('academic_period_id', $data) ? $data['academic_period_id'] : $structure->academic_period_id,
            'academic_offering_id' => array_key_exists('academic_offering_id', $data) ? $data['academic_offering_id'] : $structure->academic_offering_id,
            'program_class_id' => array_key_exists('program_class_id', $data) ? $data['program_class_id'] : $structure->program_class_id,
        ];
        $this->validateScopeHierarchy($mergedScope);

        $effectiveFrom = $data['effective_from'] ?? $structure->effective_from->format('Y-m-d');
        $effectiveTo = array_key_exists('effective_to', $data)
            ? $data['effective_to']
            : ($structure->effective_to?->format('Y-m-d'));
        $this->validateDates($effectiveFrom, $effectiveTo);

        $resolvedItems = isset($data['items']) ? $this->validateAndPrepareItems($data['items']) : null;

        return DB::transaction(function () use ($structure, $mergedScope, $effectiveFrom, $effectiveTo, $data, $resolvedItems) {
            $classId = $mergedScope['program_class_id'];
            if (!empty($mergedScope['academic_offering_id']) && !$classId) {
                $offering = AcademicOffering::find((int) $mergedScope['academic_offering_id']);
                $classId = $offering?->program_class_id;
            }

            $structure->program_id = (int) $mergedScope['program_id'];
            $structure->academic_period_id = !empty($mergedScope['academic_period_id']) ? (int) $mergedScope['academic_period_id'] : null;
            $structure->academic_offering_id = !empty($mergedScope['academic_offering_id']) ? (int) $mergedScope['academic_offering_id'] : null;
            $structure->program_class_id = !empty($classId) ? (int) $classId : null;
            $structure->effective_from = $effectiveFrom;
            $structure->effective_to = $effectiveTo;
            if (array_key_exists('notes', $data)) {
                $structure->notes = $data['notes'];
            }
            $structure->lock_version += 1;
            $structure->save();

            if ($resolvedItems !== null) {
                $structure->items()->delete();
                foreach ($resolvedItems as $idx => $itemData) {
                    FeeStructureItem::create([
                        'fee_structure_id' => $structure->id,
                        'fee_category_id' => $itemData['fee_category_id'],
                        'cadence' => $itemData['cadence'],
                        'amount' => $itemData['amount'],
                        'admission_required' => $itemData['admission_required'],
                        'due_rule' => $itemData['due_rule'],
                        'sort_order' => $itemData['sort_order'] ?? ($idx + 1),
                    ]);
                }
            }

            return $this->getStructure($structure->id);
        });
    }

    public function publishStructure(int $id, int $actorId): FeeStructure
    {
        return DB::transaction(function () use ($id, $actorId) {
            $structure = FeeStructure::with('items')->lockForUpdate()->find($id);
            if (!$structure) {
                throw BillingDomainException::notFound('FEE_STRUCTURE_NOT_FOUND', "Fee structure #{$id} not found.");
            }

            if ($structure->status === FeeStructureStatus::PUBLISHED) {
                return $this->getStructure($structure->id);
            }

            if ($structure->status === FeeStructureStatus::ARCHIVED) {
                throw BillingDomainException::conflict(
                    'FEE_STRUCTURE_ARCHIVED',
                    'An archived fee structure cannot be re-published.'
                );
            }

            if ($structure->items->isEmpty()) {
                throw BillingDomainException::validation(
                    'EMPTY_FEE_STRUCTURE',
                    'Cannot publish a fee structure with zero charge items.'
                );
            }

            // Check overlap at identical specificity (VAL-02, C07)
            $this->assertNoPublishedOverlap($structure);

            $structure->status = FeeStructureStatus::PUBLISHED;
            $structure->published_by = $actorId;
            $structure->published_at = now();
            $structure->lock_version += 1;
            $structure->save();

            return $this->getStructure($structure->id);
        });
    }

    public function archiveStructure(int $id, int $actorId): FeeStructure
    {
        return DB::transaction(function () use ($id) {
            $structure = FeeStructure::lockForUpdate()->find($id);
            if (!$structure) {
                throw BillingDomainException::notFound('FEE_STRUCTURE_NOT_FOUND', "Fee structure #{$id} not found.");
            }

            if ($structure->status === FeeStructureStatus::ARCHIVED) {
                return $this->getStructure($structure->id);
            }

            $structure->status = FeeStructureStatus::ARCHIVED;
            $structure->lock_version += 1;
            $structure->save();

            return $this->getStructure($structure->id);
        });
    }

    /**
     * Resolve deterministic effective published FeeStructure by specificity hierarchy:
     * 1. Exact offering + period
     * 2. Class/stage + period (offering null)
     * 3. Program + period (offering null, class null)
     * 4. Active program default (period null, offering null, class null)
     */
    public function resolveEffectiveStructure(
        int $programId,
        ?int $periodId,
        ?int $offeringId,
        ?int $classId,
        string $effectiveDate
    ): FeeStructure {
        if ($offeringId && !$classId) {
            $offering = AcademicOffering::find($offeringId);
            $classId = $offering?->program_class_id;
        }

        $tiers = [];

        if ($offeringId && $periodId) {
            $tiers[] = function ($q) use ($programId, $periodId, $offeringId) {
                $q->where('program_id', $programId)
                  ->where('academic_period_id', $periodId)
                  ->where('academic_offering_id', $offeringId);
            };
        }

        if ($classId && $periodId) {
            $tiers[] = function ($q) use ($programId, $periodId, $classId) {
                $q->where('program_id', $programId)
                  ->where('academic_period_id', $periodId)
                  ->whereNull('academic_offering_id')
                  ->where('program_class_id', $classId);
            };
        }

        if ($periodId) {
            $tiers[] = function ($q) use ($programId, $periodId) {
                $q->where('program_id', $programId)
                  ->where('academic_period_id', $periodId)
                  ->whereNull('academic_offering_id')
                  ->whereNull('program_class_id');
            };
        }

        $tiers[] = function ($q) use ($programId) {
            $q->where('program_id', $programId)
              ->whereNull('academic_period_id')
              ->whereNull('academic_offering_id')
              ->whereNull('program_class_id');
        };

        foreach ($tiers as $tierFilter) {
            $query = FeeStructure::with(['items.feeCategory', 'program', 'period', 'offering'])
                ->where('status', FeeStructureStatus::PUBLISHED->value)
                ->where('effective_from', '<=', $effectiveDate)
                ->where(function ($q) use ($effectiveDate) {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>=', $effectiveDate);
                });

            $tierFilter($query);
            $matches = $query->get();

            if ($matches->count() > 1) {
                throw BillingDomainException::conflict(
                    'FEE_STRUCTURE_AMBIGUOUS',
                    'Multiple published fee structures match at the same scope specificity and effective date.',
                    ['matching_structure_ids' => $matches->pluck('id')->all()]
                );
            }

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        throw BillingDomainException::validation(
            'FEE_STRUCTURE_NOT_FOUND',
            'No published fee structure found for the specified academic scope and effective date.',
            [
                'program_id' => $programId,
                'academic_period_id' => $periodId,
                'academic_offering_id' => $offeringId,
                'effective_date' => $effectiveDate,
            ]
        );
    }

    private function assertNoPublishedOverlap(FeeStructure $candidate): void
    {
        $query = FeeStructure::where('status', FeeStructureStatus::PUBLISHED->value)
            ->where('id', '!=', $candidate->id)
            ->where('program_id', $candidate->program_id);

        if ($candidate->academic_period_id) {
            $query->where('academic_period_id', $candidate->academic_period_id);
        } else {
            $query->whereNull('academic_period_id');
        }

        if ($candidate->academic_offering_id) {
            $query->where('academic_offering_id', $candidate->academic_offering_id);
        } else {
            $query->whereNull('academic_offering_id');
            if ($candidate->program_class_id) {
                $query->where('program_class_id', $candidate->program_class_id);
            } else {
                $query->whereNull('program_class_id');
            }
        }

        $candStart = $candidate->effective_from->format('Y-m-d');
        $candEnd = $candidate->effective_to ? $candidate->effective_to->format('Y-m-d') : '9999-12-31';

        $existingList = $query->get();
        foreach ($existingList as $existing) {
            $exStart = $existing->effective_from->format('Y-m-d');
            $exEnd = $existing->effective_to ? $existing->effective_to->format('Y-m-d') : '9999-12-31';

            if ($candStart <= $exEnd && $exStart <= $candEnd) {
                throw BillingDomainException::conflict(
                    'FEE_STRUCTURE_AMBIGUOUS',
                    "Published fee structure #{$existing->id} overlaps with effective date range [{$candStart} to {$candEnd}] at identical scope specificity.",
                    ['conflicting_structure_id' => $existing->id]
                );
            }
        }
    }

    private function validateScopeHierarchy(array $data): void
    {
        $programId = (int) ($data['program_id'] ?? 0);
        $program = AcademicProgram::find($programId);
        if (!$program) {
            throw BillingDomainException::validation('INVALID_PROGRAM_SCOPE', 'Selected academic program does not exist.');
        }

        $period = null;
        if (!empty($data['academic_period_id'])) {
            $period = AcademicPeriod::find((int) $data['academic_period_id']);
            if (!$period || (int) $period->program_id !== $program->id) {
                throw BillingDomainException::validation(
                    'SCOPE_MISMATCH_PERIOD',
                    'Selected academic period does not belong to the specified program.'
                );
            }
        }

        if (!empty($data['program_class_id'])) {
            $pClass = ProgramClass::find((int) $data['program_class_id']);
            if (!$pClass || (int) $pClass->program_id !== $program->id) {
                throw BillingDomainException::validation(
                    'SCOPE_MISMATCH_CLASS',
                    'Selected program class does not belong to the specified program.'
                );
            }
        }

        if (!empty($data['academic_offering_id'])) {
            $offering = AcademicOffering::with('programClass')->find((int) $data['academic_offering_id']);
            if (!$offering) {
                throw BillingDomainException::validation('INVALID_OFFERING_SCOPE', 'Selected academic offering does not exist.');
            }
            if ($period && (int) $offering->academic_period_id !== $period->id) {
                throw BillingDomainException::validation(
                    'SCOPE_MISMATCH_OFFERING_PERIOD',
                    'Selected academic offering does not belong to the specified academic period.'
                );
            }
            if ($offering->programClass && (int) $offering->programClass->program_id !== $program->id) {
                throw BillingDomainException::validation(
                    'SCOPE_MISMATCH_OFFERING_PROGRAM',
                    'Selected academic offering does not belong to the specified program.'
                );
            }
            if (!empty($data['program_class_id']) && (int) $offering->program_class_id !== (int) $data['program_class_id']) {
                throw BillingDomainException::validation(
                    'SCOPE_MISMATCH_OFFERING_CLASS',
                    'Selected academic offering does not match the specified program class.'
                );
            }
        }
    }

    private function validateDates(string $from, ?string $to): void
    {
        if ($to !== null && $to !== '' && $to < $from) {
            throw BillingDomainException::validation(
                'INVALID_EFFECTIVE_DATE_RANGE',
                'effective_to must be greater than or equal to effective_from.'
            );
        }
    }

    private function validateAndPrepareItems(array $items): array
    {
        if (empty($items)) {
            throw BillingDomainException::validation('EMPTY_FEE_ITEMS', 'At least one fee structure item is required.');
        }

        $prepared = [];
        $seenKeys = [];

        foreach ($items as $idx => $item) {
            $category = null;
            if (!empty($item['fee_category_id'])) {
                $category = FeeCategory::find((int) $item['fee_category_id']);
            } elseif (!empty($item['fee_category_code'])) {
                $category = FeeCategory::where('code', strtoupper(trim($item['fee_category_code'])))->first();
            }

            if (!$category || !$category->is_active) {
                throw BillingDomainException::validation(
                    'INVALID_FEE_CATEGORY',
                    'Fee category is missing or inactive at line ' . ($idx + 1) . '.'
                );
            }

            $cadenceStr = $item['cadence'] ?? 'PER_ENROLLMENT';
            $cadence = FeeCadence::tryFrom($cadenceStr);
            if (!$cadence) {
                throw BillingDomainException::validation(
                    'INVALID_FEE_CADENCE',
                    "Invalid cadence '{$cadenceStr}' at line " . ($idx + 1) . '.'
                );
            }

            $amountRaw = (string) ($item['amount'] ?? '');
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $amountRaw)) {
                throw BillingDomainException::validation(
                    'INVALID_MONETARY_AMOUNT',
                    "Invalid or negative charge amount '{$amountRaw}' at line " . ($idx + 1) . '. Only non-negative amounts with up to 2 decimals are permitted.'
                );
            }

            $amountFormatted = number_format((float) $amountRaw, 2, '.', '');
            if (bccomp($amountFormatted, '0.00', 2) < 0) {
                throw BillingDomainException::validation('NEGATIVE_CHARGE_FORBIDDEN', 'Negative charge amounts are forbidden.');
            }

            $uniqueKey = "{$category->id}:{$cadence->value}";
            if (isset($seenKeys[$uniqueKey])) {
                throw BillingDomainException::validation(
                    'DUPLICATE_STRUCTURE_LINE',
                    "Duplicate category '{$category->code}' and cadence '{$cadence->value}' in structure items."
                );
            }
            $seenKeys[$uniqueKey] = true;

            $prepared[] = [
                'fee_category_id' => $category->id,
                'cadence' => $cadence,
                'amount' => $amountFormatted,
                'admission_required' => (bool) ($item['admission_required'] ?? false),
                'due_rule' => $item['due_rule'] ?? null,
                'sort_order' => (int) ($item['sort_order'] ?? ($idx + 1)),
            ];
        }

        return $prepared;
    }
}

