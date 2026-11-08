<?php

namespace App\Services\Reports;

use App\Exceptions\ReportDomainException;
use App\Models\User;

class ReportCatalogService
{
    protected array $catalog = [
        'student_roster' => [
            'report_key' => 'student_roster',
            'export_dataset_key' => 'd09_student_roster',
            'name_bn' => 'শিক্ষার্থী তালিকা বিবরণী',
            'name_en' => 'Student Roster Report',
            'description_bn' => 'নির্দিষ্ট শিক্ষাবর্ষ ও শ্রেণির শিক্ষার্থীদের প্রাথমিক পরিচিতি ও অভিভাবক তথ্য।',
            'description_en' => 'Student master profile roster with guardian info and restricted PII masking.',
            'category' => 'ACADEMIC',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'allowed_dimensions' => ['academicScheme', 'academicPeriodIds', 'cohortIds', 'departmentIds', 'programIds', 'classIds', 'sectionIds', 'status', 'gender', 'search'],
            'columns' => [
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'name_bn' => ['key' => 'name_bn', 'name_bn' => 'নাম (বাংলা)', 'name_en' => 'Name (Bengali)', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'name_en' => ['key' => 'name_en', 'name_bn' => 'নাম (ইংরেজি)', 'name_en' => 'Name (English)', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'gender' => ['key' => 'gender', 'name_bn' => 'লিঙ্গ', 'name_en' => 'Gender', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'program' => ['key' => 'program', 'name_bn' => 'প্রোগ্রাম', 'name_en' => 'Program', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'class' => ['key' => 'class', 'name_bn' => 'শ্রেণি', 'name_en' => 'Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'section' => ['key' => 'section', 'name_bn' => 'শাখা', 'name_en' => 'Section', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'roll_number' => ['key' => 'roll_number', 'name_bn' => 'রোল নম্বর', 'name_en' => 'Roll No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'guardian_name' => ['key' => 'guardian_name', 'name_bn' => 'অভিভাবকের নাম', 'name_en' => 'Guardian Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'guardian_phone' => ['key' => 'guardian_phone', 'name_bn' => 'অভিভাবকের ফোন', 'name_en' => 'Guardian Phone', 'type' => 'STRING', 'sensitivity' => 'SENSITIVE'],
                'status' => ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'student_id', 'direction' => 'asc'],
        ],
        'enrollment_summary' => [
            'report_key' => 'enrollment_summary',
            'export_dataset_key' => 'd09_enrollment_summary',
            'name_bn' => 'শ্রেণিভিত্তিক ভর্তি সারসংক্ষেপ',
            'name_en' => 'Enrollment Summary',
            'description_bn' => 'শিক্ষাবর্ষ, বিভাগ ও শাখাওয়ারী মোট অনুমোদিত ও সক্রিয় ভর্তি সংখ্যা।',
            'description_en' => 'Count of valid current enrollments per explicit study period and class offering.',
            'category' => 'ACADEMIC',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'allowed_dimensions' => ['academicScheme', 'academicPeriodIds', 'departmentIds', 'programIds', 'classIds'],
            'columns' => [
                'period' => ['key' => 'period', 'name_bn' => 'শিক্ষাবর্ষ/পিরিয়ড', 'name_en' => 'Academic Period', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'department' => ['key' => 'department', 'name_bn' => 'বিভাগ', 'name_en' => 'Department', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'program' => ['key' => 'program', 'name_bn' => 'প্রোগ্রাম', 'name_en' => 'Program', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'class' => ['key' => 'class', 'name_bn' => 'শ্রেণি', 'name_en' => 'Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'section' => ['key' => 'section', 'name_bn' => 'শাখা', 'name_en' => 'Section', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'capacity' => ['key' => 'capacity', 'name_bn' => 'ধারণক্ষমতা', 'name_en' => 'Capacity', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'enrolled_count' => ['key' => 'enrolled_count', 'name_bn' => 'ভর্তিকৃত শিক্ষার্থী', 'name_en' => 'Enrolled Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'approved_count' => ['key' => 'approved_count', 'name_bn' => 'অনুমোদিত শিক্ষার্থী', 'name_en' => 'Approved Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'pending_count' => ['key' => 'pending_count', 'name_bn' => 'অপেক্ষমাণ শিক্ষার্থী', 'name_en' => 'Pending Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'class', 'direction' => 'asc'],
        ],
        'admission_status' => [
            'report_key' => 'admission_status',
            'export_dataset_key' => 'd09_admission_status',
            'name_bn' => 'ভর্তি অবস্থা ও অগ্রগতি',
            'name_en' => 'Admission Status Breakdown',
            'description_bn' => 'আবেদন, বিলিং অপেক্ষমাণ, পেমেন্ট অপেক্ষমাণ ও অনুমোদিত ভর্তির পূর্ণাঙ্গ ট্র্যাকিং।',
            'description_en' => 'Count by internal admission status; internal approval independent of external board clearance.',
            'category' => 'ACADEMIC',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'allowed_dimensions' => ['academicScheme', 'academicPeriodIds', 'cohortIds', 'departmentIds', 'programIds', 'status', 'startDate', 'endDate'],
            'columns' => [
                'status' => ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Admission Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'count' => ['key' => 'count', 'name_bn' => 'শিক্ষার্থী সংখ্যা', 'name_en' => 'Student Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'percentage' => ['key' => 'percentage', 'name_bn' => 'শতকরা হার', 'name_en' => 'Percentage', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'status', 'direction' => 'asc'],
        ],
        'promotion_outcomes' => [
            'report_key' => 'promotion_outcomes',
            'export_dataset_key' => 'd09_promotion_outcomes',
            'name_bn' => 'বার্ষিক প্রমোশন ফলাফল বিবরণী',
            'name_en' => 'Promotion Outcomes Report',
            'description_bn' => 'প্রমোশন ব্যাচ অনুসারে উত্তীর্ণ, অনুত্তীর্ণ ও স্কিপকৃত শিক্ষার্থীদের নির্ভুল পরিসংখ্যান।',
            'description_en' => 'Promotion batch outcomes grounded strictly in promotion_items decision records.',
            'category' => 'ACADEMIC',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'allowed_dimensions' => ['batchId', 'academicPeriodIds', 'programIds', 'status'],
            'columns' => [
                'batch_number' => ['key' => 'batch_number', 'name_bn' => 'ব্যাচ নম্বর', 'name_en' => 'Batch Number', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_name' => ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'from_class' => ['key' => 'from_class', 'name_bn' => 'পূর্ববর্তী শ্রেণি', 'name_en' => 'From Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'to_class' => ['key' => 'to_class', 'name_bn' => 'পরবর্তী শ্রেণি', 'name_en' => 'To Class', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'status' => ['key' => 'status', 'name_bn' => 'ফলাফল', 'name_en' => 'Outcome Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'decided_at' => ['key' => 'decided_at', 'name_bn' => 'সিদ্ধান্তের সময়', 'name_en' => 'Decided At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'student_id', 'direction' => 'asc'],
        ],
        'cohort_progression' => [
            'report_key' => 'cohort_progression',
            'export_dataset_key' => 'd09_cohort_progression',
            'name_bn' => 'কোহর্ট অগ্রগতি বিশ্লেষণ',
            'name_en' => 'Cohort Progression Analysis',
            'description_bn' => 'সেশনভিত্তিক কোহর্টের অনন্য শিক্ষার্থী সংখ্যা ও পর্যায়ক্রমিক শিক্ষাবর্ষ অগ্রগতি (কোহর্ট মোট কখনও পিরিয়ডের যোগফল নয়)।',
            'description_en' => 'Distinct students by admission cohort with study-stage breakdown. Never sums stage rows as cohort headcount.',
            'category' => 'ACADEMIC',
            'allowed_roles' => ['ADMIN', 'OFFICE_STAFF'],
            'allowed_dimensions' => ['cohortIds', 'programIds', 'departmentIds'],
            'columns' => [
                'cohort_name' => ['key' => 'cohort_name', 'name_bn' => 'কোহর্ট / সেশন', 'name_en' => 'Cohort Session', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'program' => ['key' => 'program', 'name_bn' => 'প্রোগ্রাম', 'name_en' => 'Program', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'intake_year' => ['key' => 'intake_year', 'name_bn' => 'ভর্তি শিক্ষাবর্ষ', 'name_en' => 'Intake Year', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'unique_headcount' => ['key' => 'unique_headcount', 'name_bn' => 'অনন্য শিক্ষার্থী সংখ্যা', 'name_en' => 'Unique Cohort Students', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'stage_breakdown' => ['key' => 'stage_breakdown', 'name_bn' => 'পর্যায়ক্রমিক বিবরণ', 'name_en' => 'Stage Breakdown', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'intake_year', 'direction' => 'desc'],
        ],
        'fee_assessment' => [
            'report_key' => 'fee_assessment',
            'export_dataset_key' => 'd09_fee_assessment',
            'name_bn' => 'ফি ধার্য ও মূল্যায়ন বিবরণী',
            'name_en' => 'Fee Assessment Summary',
            'description_bn' => 'ইস্যুকৃত ইনভয়েস খাতওয়ারী মোট ধার্য, ছাড় এবং প্রকৃত নিট মূল্যায়নের হিসাব।',
            'description_en' => 'Net assessed charges by fee category and academic period. Excludes void and unissued drafts.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['academicPeriodIds', 'feeCategoryIds', 'departmentIds', 'programIds', 'asOfDate'],
            'columns' => [
                'category_name' => ['key' => 'category_name', 'name_bn' => 'ফি খাত', 'name_en' => 'Fee Category', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'gross_assessed' => ['key' => 'gross_assessed', 'name_bn' => 'মোট ধার্য (টাকা)', 'name_en' => 'Gross Assessed (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'waivers_applied' => ['key' => 'waivers_applied', 'name_bn' => 'অনুমোদিত ছাড় (টাকা)', 'name_en' => 'Approved Waivers (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'net_assessed' => ['key' => 'net_assessed', 'name_bn' => 'নিট প্রদেয় (টাকা)', 'name_en' => 'Net Assessed (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'category_name', 'direction' => 'asc'],
        ],
        'student_fee_ledger' => [
            'report_key' => 'student_fee_ledger',
            'export_dataset_key' => 'd09_student_fee_ledger',
            'name_bn' => 'শিক্ষার্থী ফি লেজার খতিয়ান',
            'name_en' => 'Student Fee Ledger',
            'description_bn' => 'শিক্ষার্থীভিত্তিক ইনভয়েস, পেমেন্ট বণ্টন, রিভার্সাল ও চলমান অবশিষ্ট বকেয়া খতিয়ান।',
            'description_en' => 'Student-level billing ledger reconciling invoices, waivers, allocations, and open balance.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['academicPeriodIds', 'studentId', 'search', 'asOfDate'],
            'columns' => [
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_name' => ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'invoice_number' => ['key' => 'invoice_number', 'name_bn' => 'ইনভয়েস নম্বর', 'name_en' => 'Invoice No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'net_assessed' => ['key' => 'net_assessed', 'name_bn' => 'ধার্য ফি (টাকা)', 'name_en' => 'Net Assessed (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'allocated_paid' => ['key' => 'allocated_paid', 'name_bn' => 'পরিশোধিত (টাকা)', 'name_en' => 'Allocated Paid (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'open_balance' => ['key' => 'open_balance', 'name_bn' => 'অবশিষ্ট বকেয়া (টাকা)', 'name_en' => 'Open Balance (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'status' => ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'student_id', 'direction' => 'asc'],
        ],
        'outstanding_balances' => [
            'report_key' => 'outstanding_balances',
            'export_dataset_key' => 'd09_outstanding_balances',
            'name_bn' => 'বকেয়া ফি বিবরণী তালিকা',
            'name_en' => 'Outstanding Balances Report',
            'description_bn' => 'নির্দিষ্ট কাট-অফ তারিখ পর্যন্ত অপরিশোধিত ইনভয়েস আইটেমের বকেয়া তালিকা।',
            'description_en' => 'As-of invoice-item receivable amount; clamps individual item balance >= 0.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['academicPeriodIds', 'feeCategoryIds', 'departmentIds', 'programIds', 'asOfDate'],
            'columns' => [
                'invoice_number' => ['key' => 'invoice_number', 'name_bn' => 'ইনভয়েস নম্বর', 'name_en' => 'Invoice No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_name' => ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'item_description' => ['key' => 'item_description', 'name_bn' => 'ফি বিবরণ', 'name_en' => 'Fee Item Description', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'due_date' => ['key' => 'due_date', 'name_bn' => 'পরিশোধের শেষ তারিখ', 'name_en' => 'Due Date', 'type' => 'DATE', 'sensitivity' => 'ALLOWED'],
                'open_amount' => ['key' => 'open_amount', 'name_bn' => 'বকেয়া পরিমাণ (টাকা)', 'name_en' => 'Open Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'due_date', 'direction' => 'asc'],
        ],
        'overdue_aging' => [
            'report_key' => 'overdue_aging',
            'export_dataset_key' => 'd09_overdue_aging',
            'name_bn' => 'মেয়াদোত্তীর্ণ বকেয়ার বয়স ভিত্তিক বিশ্লেষণ',
            'name_en' => 'Overdue Receivables Aging Analysis',
            'description_bn' => 'পরিশোধের শেষ তারিখের ভিত্তিতে ৫টি স্বতন্ত্র সময়সীমার মধ্যে বকেয়ার বিভাজন।',
            'description_en' => 'Five mutually exclusive aging buckets: NOT_DUE, 0-30, 31-60, 61-90, and >90 days based on local dates.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['academicPeriodIds', 'departmentIds', 'programIds', 'feeCategoryIds', 'asOfDate'],
            'columns' => [
                'bucket' => ['key' => 'bucket', 'name_bn' => 'বকেয়া সময়সীমা', 'name_en' => 'Aging Bucket', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'total_amount' => ['key' => 'total_amount', 'name_bn' => 'মোট বকেয়া (টাকা)', 'name_en' => 'Total Balance (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'invoice_count' => ['key' => 'invoice_count', 'name_bn' => 'ইনভয়েস সংখ্যা', 'name_en' => 'Invoice Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'student_count' => ['key' => 'student_count', 'name_bn' => 'শিক্ষার্থী সংখ্যা', 'name_en' => 'Student Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'bucket', 'direction' => 'asc'],
        ],
        'cash_collections' => [
            'report_key' => 'cash_collections',
            'export_dataset_key' => 'd09_cash_collections',
            'name_bn' => 'নগদ জমা ও আদায় খতিয়ান',
            'name_en' => 'Cash Collections Register',
            'description_bn' => 'পোস্টকৃত মানি রসিদ ও অনুমোদিত রিভার্সাল সমন্বয়কৃত নগদ অর্থ প্রাপ্তি।',
            'description_en' => 'Gross posted cash less effective approved reversals in selected date window. Asia/Dhaka time.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['startDate', 'endDate', 'cashierIds', 'paymentMethod'],
            'columns' => [
                'transaction_id' => ['key' => 'transaction_id', 'name_bn' => 'লেনদেন রেফারেন্স', 'name_en' => 'Transaction Ref', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_name' => ['key' => 'student_name', 'name_bn' => 'শিক্ষার্থীর নাম', 'name_en' => 'Student Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'paid_at' => ['key' => 'paid_at', 'name_bn' => 'আদায়ের সময়', 'name_en' => 'Paid At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                'cashier' => ['key' => 'cashier', 'name_bn' => 'আদায়কারী ক্যাশিয়ার', 'name_en' => 'Cashier', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'gross_amount' => ['key' => 'gross_amount', 'name_bn' => 'মোট আদায় (টাকা)', 'name_en' => 'Gross Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'reversed_amount' => ['key' => 'reversed_amount', 'name_bn' => 'রিভার্সাল (টাকা)', 'name_en' => 'Reversed (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'net_amount' => ['key' => 'net_amount', 'name_bn' => 'নিট ক্যাশ (টাকা)', 'name_en' => 'Net Cash (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'paid_at', 'direction' => 'desc'],
        ],
        'cashier_reconciliation' => [
            'report_key' => 'cashier_reconciliation',
            'export_dataset_key' => 'd09_cashier_reconciliation',
            'name_bn' => 'ক্যাশিয়ার শিফট সমন্বয় বিবরণী',
            'name_en' => 'Cashier Reconciliation Report',
            'description_bn' => 'প্রতিটি ক্যাশিয়ার শিফটের প্রারম্ভিক ব্যালেন্স, মোট আদায়, গণনাকৃত অর্থ ও বৈষম্য/পার্থক্য।',
            'description_en' => 'Shift-level cash summary, reversals, counted cash and variance reconciliation. Control only.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['startDate', 'endDate', 'cashierIds', 'status'],
            'columns' => [
                'shift_number' => ['key' => 'shift_number', 'name_bn' => 'শিফট নম্বর', 'name_en' => 'Shift No', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'cashier_name' => ['key' => 'cashier_name', 'name_bn' => 'ক্যাশিয়ার', 'name_en' => 'Cashier Name', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'opened_at' => ['key' => 'opened_at', 'name_bn' => 'শুরু', 'name_en' => 'Opened At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                'closed_at' => ['key' => 'closed_at', 'name_bn' => 'সমাপ্তি', 'name_en' => 'Closed At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                'expected_cash' => ['key' => 'expected_cash', 'name_bn' => 'প্রত্যাশিত ক্যাশ (টাকা)', 'name_en' => 'Expected Cash (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'counted_cash' => ['key' => 'counted_cash', 'name_bn' => 'গণনাকৃত ক্যাশ (টাকা)', 'name_en' => 'Counted Cash (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'variance' => ['key' => 'variance', 'name_bn' => 'পার্থক্য (টাকা)', 'name_en' => 'Variance (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'status' => ['key' => 'status', 'name_bn' => 'অবস্থা', 'name_en' => 'Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'opened_at', 'direction' => 'desc'],
        ],
        'reversal_audit' => [
            'report_key' => 'reversal_audit',
            'export_dataset_key' => 'd09_reversal_audit',
            'name_bn' => 'পেমেন্ট রিভার্সাল ও ফেরত অডিট',
            'name_en' => 'Payment Reversals Audit',
            'description_bn' => 'অনুমোদিত সকল রিভার্সালের কারণ, অনুমোদনকারী ও কার্যকর সময়সূচি।',
            'description_en' => 'Authorized auditable reversal records showing original collected date, effective date, and net impact.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['startDate', 'endDate', 'cashierIds'],
            'columns' => [
                'reversal_reference' => ['key' => 'reversal_reference', 'name_bn' => 'রিভার্সাল রেফারেন্স', 'name_en' => 'Reversal Ref', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'payment_reference' => ['key' => 'payment_reference', 'name_bn' => 'আসল পেমেন্ট রসিদ', 'name_en' => 'Original Payment Ref', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'student_id' => ['key' => 'student_id', 'name_bn' => 'শিক্ষার্থী আইডি', 'name_en' => 'Student ID', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'reversed_amount' => ['key' => 'reversed_amount', 'name_bn' => 'ফেরত পরিমাণ (টাকা)', 'name_en' => 'Reversed Amount (BDT)', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'reversed_at' => ['key' => 'reversed_at', 'name_bn' => 'কার্যকরের সময়', 'name_en' => 'Reversed At', 'type' => 'DATETIME', 'sensitivity' => 'ALLOWED'],
                'approved_by' => ['key' => 'approved_by', 'name_bn' => 'অনুমোদনকারী', 'name_en' => 'Approved By', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'reversed_at', 'direction' => 'desc'],
        ],
        'sms_delivery_summary' => [
            'report_key' => 'sms_delivery_summary',
            'export_dataset_key' => 'd09_sms_delivery_summary',
            'name_bn' => 'অভিভাবক এসএমএস প্রেরণ প্রতিবেদন',
            'name_en' => 'Guardian SMS Delivery Summary',
            'description_bn' => 'ফি জমার মানি রসিদ এসএমএস প্রেরণ ও ডেলিভারির পরিসংখ্যান (ব্যর্থ এসএমএস কোনো পেমেন্ট বাতিল করে না)।',
            'description_en' => 'Counts queued/sent/failed/retried SMS notifications. Failed SMS has zero impact on financial truth.',
            'category' => 'FINANCIAL',
            'allowed_roles' => ['ADMIN', 'ACCOUNTS'],
            'allowed_dimensions' => ['startDate', 'endDate', 'status'],
            'columns' => [
                'status' => ['key' => 'status', 'name_bn' => 'ডেলিভারি অবস্থা', 'name_en' => 'Delivery Status', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
                'count' => ['key' => 'count', 'name_bn' => 'বার্তা সংখ্যা', 'name_en' => 'Message Count', 'type' => 'NUMBER', 'sensitivity' => 'ALLOWED'],
                'percentage' => ['key' => 'percentage', 'name_bn' => 'শতকরা হার', 'name_en' => 'Percentage', 'type' => 'STRING', 'sensitivity' => 'ALLOWED'],
            ],
            'default_sort' => ['field' => 'status', 'direction' => 'asc'],
        ],
    ];

    /**
     * Return catalog of reports permitted for the actor.
     */
    public function getCatalog(User $actor): array
    {
        $scope = new AuthorizedReportScope($actor);
        $result = [];

        foreach ($this->catalog as $key => $report) {
            if ($scope->canAccessReport($key)) {
                $result[] = [
                    'report_key' => $report['report_key'],
                    'export_dataset_key' => $report['export_dataset_key'],
                    'name_bn' => $report['name_bn'],
                    'name_en' => $report['name_en'],
                    'description_bn' => $report['description_bn'],
                    'description_en' => $report['description_en'],
                    'category' => $report['category'],
                    'allowed_dimensions' => $report['allowed_dimensions'],
                ];
            }
        }

        return $result;
    }

    /**
     * Get definition for single report.
     */
    public function getDefinition(string $reportKey, User $actor): array
    {
        if (!isset($this->catalog[$reportKey])) {
            throw ReportDomainException::notFound('REPORT_NOT_FOUND', "Report '{$reportKey}' is not found in the catalog.");
        }

        $scope = new AuthorizedReportScope($actor);
        if (!$scope->canAccessReport($reportKey)) {
            throw ReportDomainException::forbidden('FORBIDDEN_REPORT', "You do not have permission to access report '{$reportKey}'.");
        }

        $report = $this->catalog[$reportKey];

        // Format columns array for response
        $columns = array_values($report['columns']);

        return [
            'report_key' => $report['report_key'],
            'export_dataset_key' => $report['export_dataset_key'],
            'name_bn' => $report['name_bn'],
            'name_en' => $report['name_en'],
            'description_bn' => $report['description_bn'],
            'description_en' => $report['description_en'],
            'category' => $report['category'],
            'allowed_dimensions' => $report['allowed_dimensions'],
            'columns' => $columns,
            'default_sort' => $report['default_sort'],
        ];
    }
}
