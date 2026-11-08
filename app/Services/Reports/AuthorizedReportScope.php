<?php

namespace App\Services\Reports;

use App\Exceptions\ReportDomainException;
use App\Models\User;

class AuthorizedReportScope
{
    public readonly array $roleNames;
    public readonly bool $isAdmin;
    public readonly bool $isAccounts;
    public readonly bool $isStaff;
    public readonly ?array $allowedDepartmentIds;
    public readonly ?array $allowedProgramIds;
    public readonly string $scopeHash;

    public function __construct(
        public readonly User $actor
    ) {
        $this->roleNames = $actor->roles->pluck('key')->map(fn($k) => strtoupper($k))->toArray();
        $this->isAdmin = in_array('ADMIN', $this->roleNames, true);
        $this->isAccounts = in_array('ACCOUNTS', $this->roleNames, true) && !$this->isAdmin;
        $this->isStaff = in_array('OFFICE_STAFF', $this->roleNames, true) && !$this->isAdmin;

        $this->allowedDepartmentIds = is_array($actor->allowed_department_ids) && count($actor->allowed_department_ids) > 0
            ? array_map('intval', $actor->allowed_department_ids)
            : null;

        $this->allowedProgramIds = is_array($actor->allowed_program_ids) && count($actor->allowed_program_ids) > 0
            ? array_map('intval', $actor->allowed_program_ids)
            : null;

        $this->scopeHash = hash('sha256', json_encode([
            'user_id' => $actor->id,
            'roles' => $this->roleNames,
            'depts' => $this->allowedDepartmentIds,
            'progs' => $this->allowedProgramIds,
        ]));
    }

    /**
     * Verify whether requested filter values are inside the actor's organizational scope.
     * Throws 403 FORBIDDEN_SCOPE if actor forged a department/program outside their scope.
     */
    public function assertFilterScope(array $filters): void
    {
        // 1. Department Scope check
        if ($this->allowedDepartmentIds !== null) {
            if (!empty($filters['department_id']) && !in_array((int)$filters['department_id'], $this->allowedDepartmentIds, true)) {
                throw ReportDomainException::forbiddenScope("Department ID {$filters['department_id']} is outside your authorized scope.");
            }
            if (!empty($filters['department_ids']) && is_array($filters['department_ids'])) {
                foreach ($filters['department_ids'] as $dId) {
                    if (!in_array((int)$dId, $this->allowedDepartmentIds, true)) {
                        throw ReportDomainException::forbiddenScope("Department ID {$dId} is outside your authorized scope.");
                    }
                }
            }
        }

        // 2. Program Scope check
        if ($this->allowedProgramIds !== null) {
            if (!empty($filters['program_id']) && !in_array((int)$filters['program_id'], $this->allowedProgramIds, true)) {
                throw ReportDomainException::forbiddenScope("Program ID {$filters['program_id']} is outside your authorized scope.");
            }
            if (!empty($filters['program_ids']) && is_array($filters['program_ids'])) {
                foreach ($filters['program_ids'] as $pId) {
                    if (!in_array((int)$pId, $this->allowedProgramIds, true)) {
                        throw ReportDomainException::forbiddenScope("Program ID {$pId} is outside your authorized scope.");
                    }
                }
            }
        }
    }

    /**
     * Check if actor is allowed to query a given metric code.
     */
    public function canAccessMetric(string $metricCode): bool
    {
        if ($this->isAdmin) {
            return true;
        }

        $academicMetrics = [
            'active_student_count',
            'approved_admissions',
            'pending_billing',
        ];

        $financialMetrics = [
            'gross_cash',
            'approved_reversals',
            'net_cash',
            'net_assessed',
            'outstanding_as_of',
            'overdue_90_plus',
            'cashier_variance',
        ];

        if ($this->isStaff) {
            return in_array($metricCode, $academicMetrics, true);
        }

        if ($this->isAccounts) {
            return in_array($metricCode, $financialMetrics, true);
        }

        return false;
    }

    /**
     * Check if actor is allowed to query a given report key.
     */
    public function canAccessReport(string $reportKey): bool
    {
        if ($this->isAdmin) {
            return true;
        }

        $academicReports = [
            'student_roster',
            'enrollment_summary',
            'admission_status',
            'promotion_outcomes',
            'cohort_progression',
        ];

        $financialReports = [
            'fee_assessment',
            'student_fee_ledger',
            'outstanding_balances',
            'overdue_aging',
            'cash_collections',
            'cashier_reconciliation',
            'reversal_audit',
            'sms_delivery_summary',
        ];

        if ($this->isStaff) {
            return in_array($reportKey, $academicReports, true);
        }

        if ($this->isAccounts) {
            return in_array($reportKey, $financialReports, true);
        }

        return false;
    }

    /**
     * Mask sensitive phone number e.g. 01712345678 -> 017*****678
     */
    public function maskPhone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        if ($this->isAdmin) {
            return $phone;
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
     * Mask national ID or birth reg number.
     */
    public function maskRestricted(?string $value): string
    {
        if (!$value) {
            return '';
        }
        return $this->isAdmin ? $value : '***';
    }
}
