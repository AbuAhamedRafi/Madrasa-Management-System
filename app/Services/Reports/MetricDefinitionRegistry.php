<?php

namespace App\Services\Reports;

use App\Exceptions\ReportDomainException;
use App\Models\User;

class MetricDefinitionRegistry
{
    /**
     * All 10 canonical metric definitions.
     */
    protected array $catalog = [
        'active_student_count' => [
            'code' => 'active_student_count',
            'name_bn' => 'সক্রিয় শিক্ষার্থী সংখ্যা',
            'name_en' => 'Active Student Count',
            'description_bn' => 'অনুমোদিত শিক্ষাবর্ষ ও সেশনে সক্রিয়ভাবে অধ্যায়নরত অনন্য শিক্ষার্থীদের গণনা (একক ছাত্র একাধিকবার গণনা করা হয় না)।',
            'description_en' => 'Distinct count of active enrolled students in specified periods/cohorts. Never inflated by multiple period enrollments.',
            'unit' => 'students',
            'formula' => 'COUNT(DISTINCT enrollments.student_id) WHERE status = APPROVED',
            'source_owner' => 'D02/D03/D04',
            'drilldown_report_key' => 'student_roster',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'dimension_whitelist' => [
                'academicScheme', 'academicPeriodIds', 'cohortIds', 'departmentIds', 'programIds', 'classIds', 'sectionIds', 'asOfDate'
            ],
        ],
        'approved_admissions' => [
            'code' => 'approved_admissions',
            'name_bn' => 'অনুমোদিত ভর্তি সংখ্যা',
            'name_en' => 'Approved Admissions Count',
            'description_bn' => 'অভ্যন্তরীণ প্রশাসনিক অনুমোদনপ্রাপ্ত ভর্তি ও এনরোলমেন্টের মোট সংখ্যা।',
            'description_en' => 'Count of admissions in internal APPROVED status within selected period/cohort.',
            'unit' => 'admissions',
            'formula' => 'COUNT(enrollments.id) WHERE status = APPROVED',
            'source_owner' => 'D04',
            'drilldown_report_key' => 'admission_status',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'dimension_whitelist' => [
                'academicScheme', 'academicPeriodIds', 'cohortIds', 'departmentIds', 'programIds', 'classIds', 'startDate', 'endDate'
            ],
        ],
        'pending_billing' => [
            'code' => 'pending_billing',
            'name_bn' => 'বিলিং অপেক্ষমাণ ভর্তি',
            'name_en' => 'Pending Billing Enrollments',
            'description_bn' => 'ভর্তি অনুমোদনের পর প্রাথমিক ফি নির্ধারণ বা বিলিং ইনভয়েস ইস্যুর অপেক্ষায় থাকা শিক্ষার্থীদের সংখ্যা।',
            'description_en' => 'Count of enrollments currently in PENDING_BILLING state. Never treated as paid or approved.',
            'unit' => 'admissions',
            'formula' => 'COUNT(enrollments.id) WHERE status = PENDING_BILLING',
            'source_owner' => 'D04/D06',
            'drilldown_report_key' => 'admission_status',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'dimension_whitelist' => [
                'academicScheme', 'academicPeriodIds', 'cohortIds', 'departmentIds', 'programIds', 'classIds'
            ],
        ],
        'gross_cash' => [
            'code' => 'gross_cash',
            'name_bn' => 'মোট আদায়কৃত নগদ ক্যাশ',
            'name_en' => 'Gross Posted Cash Collections',
            'description_bn' => 'নির্দিষ্ট সময়সীমায় পোস্টকৃত মানি রসিদের মোট নগদ অর্থ (অপ্রদত্ত পেমেন্ট স্লিপ অন্তর্ভুক্ত নয়)।',
            'description_en' => 'Sum of POSTED payments collected in [start, end) time window. Excludes unpaid payment slips.',
            'unit' => 'BDT',
            'formula' => 'SUM(payments.amount_received) WHERE status IN (POSTED, REVERSED) AND collected_at in [start, end)',
            'source_owner' => 'D07',
            'drilldown_report_key' => 'cash_collections',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'startDate', 'endDate', 'cashierIds', 'paymentMethod', 'departmentIds'
            ],
        ],
        'approved_reversals' => [
            'code' => 'approved_reversals',
            'name_bn' => 'অনুমোদিত রিভার্সাল',
            'name_en' => 'Approved Payment Reversals',
            'description_bn' => 'নির্দিষ্ট সময়সীমায় কার্যকর হওয়া অনুমোদিত পেমেন্ট রিভার্সাল বা টাকা ফেরতের মোট পরিমাণ।',
            'description_en' => 'Sum of approved payment reversals with effective timestamp in [start, end) window.',
            'unit' => 'BDT',
            'formula' => 'SUM(payment_reversals.reversed_amount) WHERE reversed_at in [start, end)',
            'source_owner' => 'D07',
            'drilldown_report_key' => 'reversal_audit',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'startDate', 'endDate', 'cashierIds'
            ],
        ],
        'net_cash' => [
            'code' => 'net_cash',
            'name_bn' => 'প্রকৃত নিট নগদ ক্যাশ',
            'name_en' => 'Net Cash Collections',
            'description_bn' => 'মোট আদায় থেকে অনুমোদিত রিভার্সাল বাদ দিয়ে প্রকৃত সংগৃহীত নগদ অর্থ।',
            'description_en' => 'Gross posted cash minus approved reversals on effective cash basis. Does not sum shift closing totals.',
            'unit' => 'BDT',
            'formula' => 'gross_cash - approved_reversals',
            'source_owner' => 'D07',
            'drilldown_report_key' => 'cash_collections',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'startDate', 'endDate', 'cashierIds', 'paymentMethod'
            ],
        ],
        'net_assessed' => [
            'code' => 'net_assessed',
            'name_bn' => 'নিট ধার্যকৃত ফি',
            'name_en' => 'Net Assessed Fees',
            'description_bn' => 'ইস্যুকৃত ইনভয়েস আইটেম থেকে অনুমোদিত ওয়েভার/ছাড় বাদ দিয়ে মোট প্রদেয় ফি।',
            'description_en' => 'Issued non-void invoice items minus approved fee waivers/credits as of cutoff.',
            'unit' => 'BDT',
            'formula' => 'SUM(invoice_items.net_amount) - SUM(approved_adjustments)',
            'source_owner' => 'D06',
            'drilldown_report_key' => 'fee_assessment',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'academicPeriodIds', 'feeCategoryIds', 'departmentIds', 'programIds', 'asOfDate'
            ],
        ],
        'outstanding_as_of' => [
            'code' => 'outstanding_as_of',
            'name_bn' => 'বর্তমান মোট বকেয়া',
            'name_en' => 'Outstanding Receivables as-of',
            'description_bn' => 'নির্ধারিত তারিখ পর্যন্ত ইস্যুকৃত ইনভয়েসের বিপরীতে অপরিশোধিত নিট বকেয়া ফি (প্রতিটি আইটেমের ন্যূনতম ব্যালেন্স শূন্য)।',
            'description_en' => 'Net assessed due as of cutoff minus net payment allocations. Clamped >= 0 per open invoice item.',
            'unit' => 'BDT',
            'formula' => 'SUM(MAX(0, item_net_assessed_as_of - item_net_allocated_as_of))',
            'source_owner' => 'D06/D07',
            'drilldown_report_key' => 'outstanding_balances',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'asOfDate', 'academicPeriodIds', 'departmentIds', 'programIds', 'feeCategoryIds'
            ],
        ],
        'overdue_90_plus' => [
            'code' => 'overdue_90_plus',
            'name_bn' => '৯০+ দিনের পুরাতন বকেয়া',
            'name_en' => 'Overdue Receivables (>90 Days)',
            'description_bn' => 'পরিশোধের শেষ তারিখ থেকে ৯১ দিন বা তার বেশি সময় ধরে অপরিশোধিত থাকা বকেয়া ফি।',
            'description_en' => 'Outstanding receivables where local due_date is >= 91 days before local asOfDate.',
            'unit' => 'BDT',
            'formula' => 'SUM(open_balance) WHERE (asOfDate - due_date) >= 91',
            'source_owner' => 'D06/D07',
            'drilldown_report_key' => 'overdue_aging',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'asOfDate', 'academicPeriodIds', 'departmentIds', 'programIds'
            ],
        ],
        'cashier_variance' => [
            'code' => 'cashier_variance',
            'name_bn' => 'ক্যাশিয়ার শিফট পার্থক্য',
            'name_en' => 'Cashier Shift Variance',
            'description_bn' => 'ক্যাশিয়ার শিফট ক্লোজিংয়ে গণনাকৃত ক্যাশ ও সফটওয়্যার প্রত্যাশিত ক্যাশের পার্থক্য।',
            'description_en' => 'Counted cash minus expected posted net cash across cashier shifts. Maintained independently from net cash.',
            'unit' => 'BDT',
            'formula' => 'SUM(cashier_shifts.counted_cash_amount - cashier_shifts.expected_cash_amount)',
            'source_owner' => 'D07',
            'drilldown_report_key' => 'cashier_reconciliation',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'dimension_whitelist' => [
                'startDate', 'endDate', 'cashierIds'
            ],
        ],
    ];

    /**
     * Get all metric definitions permitted for the actor.
     */
    public function getDefinitions(User $actor): array
    {
        $scope = new AuthorizedReportScope($actor);
        $result = [];

        foreach ($this->catalog as $code => $definition) {
            if ($scope->canAccessMetric($code)) {
                $result[] = $definition;
            }
        }

        return $result;
    }

    /**
     * Get a single metric definition if permitted.
     */
    public function getDefinition(string $metricCode, User $actor): array
    {
        if (!isset($this->catalog[$metricCode])) {
            throw ReportDomainException::notFound('METRIC_NOT_FOUND', "Metric definition '{$metricCode}' not found.");
        }

        $scope = new AuthorizedReportScope($actor);
        if (!$scope->canAccessMetric($metricCode)) {
            // Must return 404 or 403 without disclosing SQL
            throw ReportDomainException::forbidden('FORBIDDEN_METRIC', "You are not authorized to view metric '{$metricCode}'.");
        }

        return $this->catalog[$metricCode];
    }
}
