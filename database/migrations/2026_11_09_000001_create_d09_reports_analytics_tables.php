<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Report Saved Views
        Schema::create('report_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('report_key', 64)->index();
            $table->string('name', 120);
            $table->string('visibility', 20)->default('PRIVATE'); // 'PRIVATE', 'SHARED_ROLE'
            $table->jsonb('shared_roles_json')->nullable();
            $table->jsonb('filters_json')->default('{}');
            $table->jsonb('columns_json')->default('[]');
            $table->jsonb('sort_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['owner_user_id', 'report_key']);
        });

        // 2. Dashboard Preferences
        Schema::create('dashboard_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('default_dashboard', 32)->nullable(); // 'admin', 'accounts', 'staff'
            $table->string('locale_override', 8)->nullable();
            $table->jsonb('widgets_json')->nullable();
            $table->jsonb('favorite_filters_json')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // 3. Report Query Audits (Redacted PII)
        Schema::create('report_query_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type', 32); // 'REPORT_QUERY', 'DRILLDOWN', 'DASHBOARD_READ', 'SAVED_VIEW_EXEC', 'EXPORT_PREVIEW', 'EXPORT_CREATE'
            $table->string('report_key', 64)->index();
            $table->string('mode', 24)->nullable(); // 'summary', 'chart', 'rows', 'drilldown'
            $table->string('filter_hash', 64)->index();
            $table->string('authorized_scope_hash', 64)->index();
            $table->jsonb('redacted_filters_json')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('outcome', 24)->default('SUCCESS'); // 'SUCCESS', 'FORBIDDEN', 'VALIDATION_ERROR', 'STALE_WARNING'
            $table->jsonb('source_watermarks_json')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        // 4. Report Metric / Scope Caches
        Schema::create('report_metric_caches', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 191)->unique();
            $table->string('metric_or_report_key', 64)->index();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('authorized_scope_hash', 64)->index();
            $table->string('filter_hash', 64);
            $table->string('report_revision', 32)->default('v1');
            $table->jsonb('payload_json');
            $table->jsonb('source_watermarks_json')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // 5. User Scope Columns (if not exists)
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'allowed_department_ids')) {
                $table->jsonb('allowed_department_ids')->nullable()->after('preferred_locale');
            }
            if (!Schema::hasColumn('users', 'allowed_program_ids')) {
                $table->jsonb('allowed_program_ids')->nullable()->after('allowed_department_ids');
            }
        });

        // 6. Evidence-Based Read Indexes for Analytical Aggregation
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['academic_period_id', 'status', 'student_id'], 'idx_enrollments_period_status_student');
        });

        Schema::table('program_admissions', function (Blueprint $table) {
            $table->index(['academic_cohort_id', 'status', 'student_id'], 'idx_prog_admissions_cohort_status_student');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['status', 'due_at', 'academic_period_id'], 'idx_invoices_status_due_period');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['status', 'collected_at', 'collected_by'], 'idx_payments_status_collected_at_by');
        });

        Schema::table('payment_reversals', function (Blueprint $table) {
            $table->index(['reversed_at', 'payment_id'], 'idx_payment_reversals_reversed_at_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_reversals', function (Blueprint $table) {
            $table->dropIndex('idx_payment_reversals_reversed_at_payment');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_status_collected_at_by');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('idx_invoices_status_due_period');
        });

        Schema::table('program_admissions', function (Blueprint $table) {
            $table->dropIndex('idx_prog_admissions_cohort_status_student');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enrollments_period_status_student');
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'allowed_program_ids')) {
                $table->dropColumn('allowed_program_ids');
            }
            if (Schema::hasColumn('users', 'allowed_department_ids')) {
                $table->dropColumn('allowed_department_ids');
            }
        });

        Schema::dropIfExists('report_metric_caches');
        Schema::dropIfExists('report_query_audits');
        Schema::dropIfExists('dashboard_preferences');
        Schema::dropIfExists('report_saved_views');
    }
};
