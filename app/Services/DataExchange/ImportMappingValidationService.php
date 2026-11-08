<?php

namespace App\Services\DataExchange;

use App\Enums\ImportIssueSeverity;
use App\Enums\ImportJobStatus;
use App\Enums\ImportMode;
use App\Enums\ImportRowAction;
use App\Enums\ImportRowStatus;
use App\Exceptions\DataExchangeDomainException;
use App\Models\AcademicClass;
use App\Models\AcademicOffering;
use App\Models\AcademicSession;
use App\Models\Campus;
use App\Models\Guardian;
use App\Models\ImportColumnMapping;
use App\Models\ImportJob;
use App\Models\ImportJobEvent;
use App\Models\ImportRow;
use App\Models\ImportRowIssue;
use App\Models\ImportTemplate;
use App\Models\OfferingSection;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportMappingValidationService
{
    public function __construct(
        protected XlsxWorkbookService $xlsxService
    ) {}

    /**
     * Detect column headers and provide auto-mapping suggestions (F04).
     */
    public function detectColumns(ImportJob $job, ?string $sheet = null, int $headerRow = 1): array
    {
        $realPath = Storage::disk('local')->path($job->storage_key);
        if (!file_exists($realPath)) {
            throw DataExchangeDomainException::fileUnsafe('Quarantined import file not found.');
        }

        $sheetName = $sheet;
        if (!$sheetName) {
            $detectedSheets = $job->detected_sheets_json ?: [];
            if (in_array('Data', $detectedSheets, true)) {
                $sheetName = 'Data';
            } elseif (!empty($detectedSheets)) {
                $sheetName = $detectedSheets[0];
            } else {
                $sheetName = 'Data';
            }
        }

        $rows = $this->xlsxService->extractSheetRows($realPath, $sheetName, $headerRow + 5);
        $headerCells = $rows[$headerRow] ?? [];

        $detectedHeaders = [];
        $suggestedMapping = [];

        $template = ImportTemplate::where('code', $job->template_code)
            ->where('version', $job->template_version)
            ->first();

        $schema = $template?->schema_json ?? [];
        $columns = $schema['columns'] ?? [];
        $allowedTargetKeys = array_keys($columns);

        foreach ($headerCells as $colIdx => $headerVal) {
            $colLetter = XlsxWorkbookService::columnLetter($colIdx);
            $samples = [];
            for ($r = $headerRow + 1; $r <= $headerRow + 5; $r++) {
                if (isset($rows[$r][$colIdx]) && trim((string)$rows[$r][$colIdx]) !== '') {
                    $samples[] = trim((string)$rows[$r][$colIdx]);
                }
            }

            $detectedHeaders[] = [
                'column_index' => $colIdx,
                'column_letter' => $colLetter,
                'header' => $headerVal,
                'sample_values' => array_slice($samples, 0, 3),
            ];

            // Match suggested target
            $normHeader = strtolower(str_replace([' ', '-', '_'], '', $headerVal));
            foreach ($allowedTargetKeys as $targetKey) {
                $normTarget = strtolower(str_replace(['.', '-', '_'], '', $targetKey));
                if ($normHeader === $normTarget || str_contains($normHeader, $normTarget) || str_contains($normTarget, $normHeader)) {
                    $suggestedMapping[$colLetter] = $targetKey;
                    break;
                }
            }
        }

        return [
            'sheet' => $sheetName,
            'header_row' => $headerRow,
            'detected_headers' => $detectedHeaders,
            'target_fields' => array_values($columns),
            'suggested_mapping' => $suggestedMapping,
            'forbidden_fields' => $template?->forbidden_fields_json ?? [],
        ];
    }

    /**
     * Persist validated column mapping and invalidate stale preview token (F04).
     *
     * @throws DataExchangeDomainException
     */
    public function saveMapping(ImportJob $job, array $payload, int $actorId): ImportColumnMapping
    {
        if (in_array($job->state, [ImportJobStatus::COMPLETED, ImportJobStatus::CANCELLED], true)) {
            throw DataExchangeDomainException::workflowConflict("Cannot modify mapping of a {$job->state->value} job.");
        }

        $sheet = $payload['sheet'] ?? 'Data';
        $headerRow = (int)($payload['header_row'] ?? 1);
        $mapping = $payload['mapping'] ?? [];
        $options = $payload['options'] ?? null;

        $template = ImportTemplate::where('code', $job->template_code)
            ->where('version', $job->template_version)
            ->first();

        $forbiddenFields = $template?->forbidden_fields_json ?: ['student_id', 'cash_paid', 'receipt_no'];
        $schema = $template?->schema_json ?? [];
        $allowedColumns = array_keys($schema['columns'] ?? []);
        $requiredFields = $schema['required_fields'] ?? [];

        // 1. Forbidden fields check (D08-F04-AT02)
        foreach ($mapping as $col => $targetField) {
            if (!$targetField) continue;

            // In MATCH_UPDATE and ENROLL_ONLY, student_id or system_student_id is the identity anchor
            if (in_array($job->mode, [ImportMode::MATCH_UPDATE, ImportMode::ENROLL_ONLY], true) && in_array($targetField, ['student_id', 'system_student_id'], true)) {
                continue;
            }

            if (in_array($targetField, $forbiddenFields, true)) {
                throw DataExchangeDomainException::columnMappingInvalid(
                    "Target field '{$targetField}' is strictly forbidden in template '{$job->template_code}'."
                );
            }

            // In CREATE_ONLY, student_id can NEVER be imported
            if ($job->mode === ImportMode::CREATE_ONLY && in_array($targetField, ['student_id', 'system_student_id'], true)) {
                throw DataExchangeDomainException::schemaViolation("student_id cannot be mapped in CREATE_ONLY mode.");
            }
        }

        // 2. Required fields check with alias support
        $aliasMap = [
            'dob' => 'date_of_birth',
            'date_of_birth' => 'dob',
            'primary_guardian_phone' => 'primary_guardian.phone',
            'primary_guardian.phone' => 'primary_guardian_phone',
        ];

        $mappedTargetValues = array_filter(array_values($mapping));
        // Expand mapped target values with their aliases
        foreach ($mappedTargetValues as $mv) {
            if (isset($aliasMap[$mv])) {
                $mappedTargetValues[] = $aliasMap[$mv];
            }
        }

        // Essential core fields required for students_create
        $coreRequired = match ($job->mode) {
            ImportMode::CREATE_ONLY => ['name_bn', 'gender'],
            ImportMode::MATCH_UPDATE => [],
            ImportMode::ENROLL_ONLY => [],
        };

        foreach ($coreRequired as $reqField) {
            if (!in_array($reqField, $mappedTargetValues, true)) {
                throw DataExchangeDomainException::schemaViolation(
                    "Required schema field '{$reqField}' is missing from column mapping."
                );
            }
        }

        // Check date of birth
        if ($job->mode === ImportMode::CREATE_ONLY) {
            if (!in_array('dob', $mappedTargetValues, true) && !in_array('date_of_birth', $mappedTargetValues, true)) {
                throw DataExchangeDomainException::schemaViolation("Date of birth field ('dob' or 'date_of_birth') is required.");
            }
            if (!in_array('primary_guardian.phone', $mappedTargetValues, true) && !in_array('primary_guardian_phone', $mappedTargetValues, true)) {
                throw DataExchangeDomainException::schemaViolation("Primary guardian phone ('primary_guardian.phone') is required.");
            }
        }

        // 3. Increment version and invalidate prior preview token
        $newVersion = $job->mapping_version + 1;

        $columnMapping = DB::transaction(function () use ($job, $sheet, $headerRow, $mapping, $options, $newVersion, $actorId) {
            $createdMapping = ImportColumnMapping::create([
                'import_job_id' => $job->id,
                'schema_version' => $job->template_version,
                'sheet' => $sheet,
                'header_row' => $headerRow,
                'mapping_json' => $mapping,
                'options_json' => $options,
                'version' => $newVersion,
                'created_by' => $actorId,
            ]);

            $job->mapping_version = $newVersion;
            $job->preview_token_hash = null;
            $job->preview_state_hash = null;
            $job->preview_expires_at = null;
            $job->state = ImportJobStatus::UPLOADED;
            $job->save();

            ImportJobEvent::create([
                'import_job_id' => $job->id,
                'actor_id' => $actorId,
                'event' => 'MAPPING_UPDATED',
                'before_state' => $job->state->value,
                'after_state' => ImportJobStatus::UPLOADED->value,
                'reason_code' => 'MAPPING_VERSION_' . $newVersion,
                'context_redacted_json' => [
                    'version' => $newVersion,
                    'sheet' => $sheet,
                    'mapped_count' => count($mapping),
                ],
                'created_at' => now(),
            ]);

            return $createdMapping;
        });

        return $columnMapping;
    }

    /**
     * Run read-only validation generating ImportRows, ImportRowIssues, and cryptographic Preview Token (F04).
     */
    public function validateImport(ImportJob $job, int $actorId): array
    {
        $mapping = $job->mappings()->latest('id')->first();
        if (!$mapping) {
            throw DataExchangeDomainException::workflowConflict('Cannot validate import job without saving column mapping first.');
        }

        $realPath = Storage::disk('local')->path($job->storage_key);
        if (!file_exists($realPath)) {
            throw DataExchangeDomainException::fileUnsafe('Quarantined import file not found.');
        }

        // Set state to VALIDATING
        $job->update(['state' => ImportJobStatus::VALIDATING]);

        // Clear prior rows & issues for clean re-validation
        $priorRowIds = ImportRow::where('import_job_id', $job->id)->pluck('id');
        ImportRowIssue::whereIn('import_row_id', $priorRowIds)->delete();
        ImportRow::where('import_job_id', $job->id)->delete();

        // Extract rows from sheet
        $allRows = $this->xlsxService->extractSheetRows($realPath, $mapping->sheet);
        $headerRow = $mapping->header_row;
        $map = $mapping->mapping_json; // [ 'A' => 'name_bn', 'B' => 'gender', ... ]

        $totalRows = 0;
        $readyRows = 0;
        $reviewRows = 0;
        $invalidRows = 0;

        foreach ($allRows as $rowNum => $rowCells) {
            if ($rowNum <= $headerRow) {
                continue;
            }

            // Skip completely empty row
            $nonEmpty = array_filter($rowCells, fn($v) => trim((string)$v) !== '');
            if (empty($nonEmpty)) {
                continue;
            }

            $totalRows++;
            $rawPayload = [];
            $normalizedPayload = [];
            $rowIssues = [];
            $hasFormula = false;

            // Map cells
            foreach ($map as $colLetter => $targetKey) {
                if (!$targetKey) continue;
                $colIdx = XlsxWorkbookService::columnLetterToIndex($colLetter);
                $rawVal = isset($rowCells[$colIdx]) ? (string)$rowCells[$colIdx] : '';
                $rawPayload[$targetKey] = $rawVal;

                // Formula injection check (D08-F04-AT03)
                if ($this->detectFormulaInjection($rawVal)) {
                    $hasFormula = true;
                    $rowIssues[] = [
                        'code' => 'FORMULA_INJECTION_DETECTED',
                        'severity' => ImportIssueSeverity::WARNING,
                        'field' => $targetKey,
                        'source_column' => $colLetter,
                        'explanation_key' => 'FORMULA_INJECTION_WARNING',
                        'message_bn' => 'স্প্রেডশিট সূত্রের সম্ভাব্য অপব্যবহার চিহ্নিত হয়েছে। মানটি স্বাভাবিক টেক্সট হিসেবে গ্রহণ করা হয়েছে।',
                        'message_en' => 'Potential spreadsheet formula injection pattern detected. Value is treated as literal text.',
                        'meta_json' => ['raw_value' => $rawVal],
                    ];
                    // Sanitize by stripping leading unsafe prefix for normalized payload
                    $cleanedVal = ltrim($rawVal, "'=+-\t\r@");
                    $normalizedPayload[$targetKey] = $cleanedVal;
                } else {
                    $normalizedPayload[$targetKey] = trim($rawVal);
                }
            }

            // Alias normalization
            if (isset($normalizedPayload['date_of_birth']) && !isset($normalizedPayload['dob'])) {
                $normalizedPayload['dob'] = $normalizedPayload['date_of_birth'];
            }
            if (isset($normalizedPayload['dob']) && !isset($normalizedPayload['date_of_birth'])) {
                $normalizedPayload['date_of_birth'] = $normalizedPayload['dob'];
            }
            if (isset($normalizedPayload['primary_guardian_phone']) && !isset($normalizedPayload['primary_guardian.phone'])) {
                $normalizedPayload['primary_guardian.phone'] = $normalizedPayload['primary_guardian_phone'];
            }

            // Field-level semantic validations (D08-F04-AT05)
            // 1. Student Name BN
            if (isset($normalizedPayload['name_bn'])) {
                $val = $normalizedPayload['name_bn'];
                if (empty($val)) {
                    $rowIssues[] = [
                        'code' => 'REQUIRED_FIELD_EMPTY',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'name_bn',
                        'source_column' => null,
                        'explanation_key' => 'NAME_BN_REQUIRED',
                        'message_bn' => 'শিক্ষার্থীর নাম (বাংলা) আবশ্যক।',
                        'message_en' => 'Student name (Bangla) is required.',
                    ];
                }
            }

            // 2. Gender
            if (isset($normalizedPayload['gender'])) {
                $g = strtoupper($normalizedPayload['gender']);
                if (in_array($g, ['ছাত্র', 'পুরুষ', 'M', 'MALE'], true)) {
                    $normalizedPayload['gender'] = 'MALE';
                } elseif (in_array($g, ['ছাত্রী', 'মহিলা', 'F', 'FEMALE'], true)) {
                    $normalizedPayload['gender'] = 'FEMALE';
                } else {
                    $rowIssues[] = [
                        'code' => 'INVALID_GENDER',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'gender',
                        'source_column' => null,
                        'explanation_key' => 'GENDER_FORMAT',
                        'message_bn' => 'লিঙ্গ মান অবশ্যই MALE অথবা FEMALE হতে হবে।',
                        'message_en' => 'Gender must be MALE or FEMALE.',
                        'meta_json' => ['given' => $normalizedPayload['gender']],
                    ];
                }
            }

            // 3. Date of Birth
            if (isset($normalizedPayload['dob'])) {
                $dobStr = $normalizedPayload['dob'];
                $dobParsed = null;
                try {
                    $dobParsed = Carbon::parse($dobStr);
                    if ($dobParsed->isFuture() || $dobParsed->year < 1950) {
                        throw new \Exception('Invalid DOB year range');
                    }
                    $normalizedPayload['dob'] = $dobParsed->format('Y-m-d');
                } catch (\Throwable $e) {
                    $rowIssues[] = [
                        'code' => 'INVALID_DOB',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'dob',
                        'source_column' => null,
                        'explanation_key' => 'DOB_FORMAT',
                        'message_bn' => 'জন্ম তারিখ অবশ্যই সঠিক ফরম্যাটে (YYYY-MM-DD) এবং অতীতের তারিখ হতে হবে।',
                        'message_en' => 'Date of birth must be a valid past date in YYYY-MM-DD format.',
                        'meta_json' => ['given' => $dobStr],
                    ];
                }
            }

            // 4. Primary Guardian Phone
            if (isset($normalizedPayload['primary_guardian.phone']) || isset($normalizedPayload['primary_guardian_phone'])) {
                $phoneKey = isset($normalizedPayload['primary_guardian.phone']) ? 'primary_guardian.phone' : 'primary_guardian_phone';
                $phone = preg_replace('/[^\d]/', '', (string)$normalizedPayload[$phoneKey]);
                // BD phone: 11 digits starting with 01
                if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
                    $rowIssues[] = [
                        'code' => 'INVALID_PHONE',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => $phoneKey,
                        'source_column' => null,
                        'explanation_key' => 'PHONE_BD_FORMAT',
                        'message_bn' => 'অভিভাবকের মোবাইল নম্বর অবশ্যই ১১ ডিজিটের বৈধ বাংলাদেশি নম্বর (০১XXXXXXXXX) হতে হবে।',
                        'message_en' => 'Primary guardian phone must be a valid 11-digit Bangladeshi mobile number (01XXXXXXXXX).',
                        'meta_json' => ['given' => $normalizedPayload[$phoneKey]],
                    ];
                } else {
                    $normalizedPayload[$phoneKey] = $phone;
                }
            }

            // 5. Academic reference checks (if present)
            if (!empty($normalizedPayload['academic.class_code'])) {
                $classCode = $normalizedPayload['academic.class_code'];
                $cls = DB::table('classes')->where('code', $classCode)->first();
                if (!$cls) {
                    $rowIssues[] = [
                        'code' => 'CLASS_NOT_FOUND',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'academic.class_code',
                        'source_column' => null,
                        'explanation_key' => 'CLASS_REFERENCE_MISSING',
                        'message_bn' => "শ্রেণি কোড '{$classCode}' পাওয়া যায়নি।",
                        'message_en' => "Academic class code '{$classCode}' not found in institution structure.",
                        'meta_json' => ['code' => $classCode],
                    ];
                }
            }

            // 6. Mode & Duplicate Check (D08-F04, F05, F06)
            $action = ImportRowAction::CREATE;
            $targetStudentPk = null;
            $candidateMatches = [];

            if ($job->mode === ImportMode::CREATE_ONLY) {
                $action = ImportRowAction::CREATE;

                // Check for potential duplicate by guardian phone and student name/dob
                $phoneVal = $normalizedPayload['primary_guardian.phone'] ?? ($normalizedPayload['primary_guardian_phone'] ?? null);
                $nameBn = $normalizedPayload['name_bn'] ?? null;
                $dob = $normalizedPayload['dob'] ?? null;

                if ($phoneVal && ($nameBn || $dob)) {
                    $existingGuardian = Guardian::where('phone', $phoneVal)->first();
                    if ($existingGuardian) {
                        $matchQuery = Student::whereHas('guardians', function ($q) use ($existingGuardian) {
                            $q->where('guardians.id', $existingGuardian->id);
                        });

                        if ($nameBn) {
                            $matchQuery->where('name_bn', 'like', "%{$nameBn}%");
                        }
                        if ($dob) {
                            $matchQuery->orWhere('date_of_birth', $dob);
                        }

                        $potentialMatches = $matchQuery->take(3)->get();
                        if ($potentialMatches->isNotEmpty()) {
                            foreach ($potentialMatches as $pm) {
                                $candidateMatches[] = [
                                    'student_id' => $pm->student_id,
                                    'name_bn' => $pm->name_bn,
                                    'name_en' => $pm->name_en,
                                    'dob' => $pm->date_of_birth?->format('Y-m-d'),
                                    'status' => $pm->status?->value ?? (string)$pm->status,
                                    'confidence' => 0.85,
                                ];
                            }

                            $rowIssues[] = [
                                'code' => 'POTENTIAL_DUPLICATE_STUDENT',
                                'severity' => ImportIssueSeverity::REVIEW,
                                'field' => 'primary_guardian.phone',
                                'source_column' => null,
                                'explanation_key' => 'DUPLICATE_SUSPECTED',
                                'message_bn' => 'একই অভিভাবক ও নামের সাথে মিলে যাওয়া বিদ্যমান শিক্ষার্থীর তথ্য পাওয়া গেছে। পর্যালোচনার প্রয়োজন।',
                                'message_en' => 'Potential duplicate existing student found for guardian and student profile. Requires review.',
                                'meta_json' => ['candidates' => $candidateMatches],
                            ];
                        }
                    }
                }
            } elseif ($job->mode === ImportMode::MATCH_UPDATE) {
                $action = ImportRowAction::MATCH_UPDATE;
                $studentIdVal = $normalizedPayload['student_id'] ?? null;
                $rowDiff = null;

                if ($studentIdVal) {
                    $existing = Student::where('student_id', $studentIdVal)->first();
                    if ($existing) {
                        $targetStudentPk = $existing->id;
                        $diff = [];
                        $comparable = ['name_bn', 'name_en', 'gender', 'dob', 'present_address_bn', 'permanent_address_bn'];
                        foreach ($comparable as $f) {
                            $src = $normalizedPayload[$f] ?? null;
                            $dbVal = $f === 'dob' ? ($existing->dob?->format('Y-m-d') ?: ($existing->date_of_birth ? Carbon::parse($existing->date_of_birth)->format('Y-m-d') : null)) : ($existing->{$f} ?? null);
                            if ($src !== null && $src !== '' && $src !== $dbVal) {
                                $diff[$f] = [
                                    'old' => $dbVal,
                                    'new' => $src,
                                    'sensitive' => in_array($f, ['name_bn', 'name_en', 'gender', 'dob'], true),
                                ];
                            }
                        }

                        $phoneVal = $normalizedPayload['primary_guardian.phone'] ?? ($normalizedPayload['primary_guardian_phone'] ?? null);
                        if ($phoneVal) {
                            $pri = $existing->guardians()->wherePivot('is_primary', true)->first();
                            if ($pri && $pri->phone !== $phoneVal) {
                                $diff['primary_guardian.phone'] = [
                                    'old' => $pri->phone,
                                    'new' => $phoneVal,
                                    'sensitive' => true,
                                ];
                            }
                        }

                        $rowDiff = empty($diff) ? null : $diff;

                        $hasSensitive = false;
                        foreach ($diff as $df) {
                            if (!empty($df['sensitive'])) {
                                $hasSensitive = true;
                                break;
                            }
                        }

                        if ($hasSensitive) {
                            $rowIssues[] = [
                                'code' => 'SENSITIVE_FIELD_UPDATE',
                                'severity' => ImportIssueSeverity::REVIEW,
                                'field' => null,
                                'source_column' => null,
                                'explanation_key' => 'SENSITIVE_DIFF_REVIEW',
                                'message_bn' => 'শিক্ষার্থীর সংবেদনশীল তথ্যে পরিবর্তন সনাক্ত হয়েছে। পর্যালোচনার প্রয়োজন।',
                                'message_en' => 'Sensitive student profile changes detected. Requires manual review.',
                                'meta_json' => ['diff' => $diff],
                            ];
                        }
                    } else {
                        $rowIssues[] = [
                            'code' => 'STUDENT_NOT_FOUND',
                            'severity' => ImportIssueSeverity::ERROR,
                            'field' => 'student_id',
                            'source_column' => null,
                            'explanation_key' => 'IDENTITY_ANCHOR_MISSING',
                            'message_bn' => "শিক্ষার্থী আইডি '{$studentIdVal}' সিস্টেমে পাওয়া যায়নি।",
                            'message_en' => "Student ID '{$studentIdVal}' does not exist.",
                            'meta_json' => ['student_id' => $studentIdVal],
                        ];
                    }
                } else {
                    $rowIssues[] = [
                        'code' => 'IDENTITY_ANCHOR_REQUIRED',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'student_id',
                        'source_column' => null,
                        'explanation_key' => 'STUDENT_ID_ANCHOR_REQUIRED',
                        'message_bn' => 'বিদ্যমান শিক্ষার্থী আপডেটের জন্য শিক্ষার্থী আইডি আবশ্যক।',
                        'message_en' => 'Student ID anchor is required for existing student updates.',
                    ];
                }
            } elseif ($job->mode === ImportMode::ENROLL_ONLY) {
                $action = ImportRowAction::ENROLL;
                $studentIdVal = $normalizedPayload['student_id'] ?? ($normalizedPayload['system_student_id'] ?? null);

                if (!$studentIdVal) {
                    $rowIssues[] = [
                        'code' => 'STUDENT_ID_REQUIRED',
                        'severity' => ImportIssueSeverity::ERROR,
                        'field' => 'student_id',
                        'source_column' => null,
                        'explanation_key' => 'ENROLL_STUDENT_ID_REQUIRED',
                        'message_bn' => 'এনরোলমেন্টের জন্য শিক্ষার্থী আইডি আবশ্যক।',
                        'message_en' => 'Student ID is required for enrollment staging.',
                    ];
                } else {
                    $student = Student::where('student_id', $studentIdVal)->first();
                    if (!$student) {
                        $rowIssues[] = [
                            'code' => 'STUDENT_NOT_FOUND',
                            'severity' => ImportIssueSeverity::ERROR,
                            'field' => 'student_id',
                            'source_column' => null,
                            'explanation_key' => 'ENROLL_STUDENT_NOT_FOUND',
                            'message_bn' => "শিক্ষার্থী আইডি '{$studentIdVal}' সিস্টেমে পাওয়া যায়নি।",
                            'message_en' => "Student ID '{$studentIdVal}' not found.",
                        ];
                    } else {
                        $targetStudentPk = $student->id;

                        // Program check
                        $programCode = $normalizedPayload['academic_program_code'] ?? ($normalizedPayload['program_code'] ?? null);
                        if ($programCode) {
                            $program = DB::table('academic_programs')->where('code', $programCode)->first();
                            if (!$program) {
                                $rowIssues[] = [
                                    'code' => 'PROGRAM_NOT_FOUND',
                                    'severity' => ImportIssueSeverity::ERROR,
                                    'field' => 'academic_program_code',
                                    'explanation_key' => 'PROGRAM_NOT_FOUND',
                                    'message_bn' => "একাডেমিক প্রোগ্রাম কোড '{$programCode}' পাওয়া যায়নি।",
                                    'message_en' => "Academic program code '{$programCode}' not found.",
                                ];
                            }
                        }

                        // Period / Session check
                        $periodCode = $normalizedPayload['academic_period_code'] ?? ($normalizedPayload['period_code'] ?? null);
                        $period = null;
                        if ($periodCode) {
                            $period = DB::table('academic_periods')
                                ->where('label', $periodCode)
                                ->orWhere('id', is_numeric($periodCode) ? (int)$periodCode : 0)
                                ->first();
                            if (!$period) {
                                $rowIssues[] = [
                                    'code' => 'PERIOD_NOT_FOUND',
                                    'severity' => ImportIssueSeverity::ERROR,
                                    'field' => 'academic_period_code',
                                    'explanation_key' => 'PERIOD_NOT_FOUND',
                                    'message_bn' => "একাডেমিক সেশন/পিরিয়ড '{$periodCode}' পাওয়া যায়নি।",
                                    'message_en' => "Academic period '{$periodCode}' not found.",
                                ];
                            }
                        }

                        // Duplicate enrollment check
                        if ($period && $student) {
                            $existingEnrollment = DB::table('enrollments')
                                ->where('student_id', $student->id)
                                ->where('academic_period_id', $period->id)
                                ->whereNotIn('status', ['CANCELLED', 'REJECTED'])
                                ->first();

                            if ($existingEnrollment) {
                                $rowIssues[] = [
                                    'code' => 'DUPLICATE_ENROLLMENT',
                                    'severity' => ImportIssueSeverity::ERROR,
                                    'field' => 'academic_period_code',
                                    'explanation_key' => 'DUPLICATE_ENROLLMENT',
                                    'message_bn' => 'শিক্ষার্থী ইতিমধ্যে এই সেশনে এনরোল্ড রয়েছে।',
                                    'message_en' => 'Student is already enrolled in this academic period.',
                                ];
                            }
                        }
                    }
                }
            }

            // Determine Row Status
            $hasError = false;
            $hasReview = false;
            foreach ($rowIssues as $iss) {
                if ($iss['severity'] === ImportIssueSeverity::ERROR) {
                    $hasError = true;
                } elseif ($iss['severity'] === ImportIssueSeverity::REVIEW) {
                    $hasReview = true;
                }
            }

            if ($hasError) {
                $rowStatus = ImportRowStatus::INVALID;
                $invalidRows++;
            } elseif ($hasReview) {
                $rowStatus = ImportRowStatus::REVIEW_REQUIRED;
                $reviewRows++;
            } else {
                $rowStatus = ImportRowStatus::READY;
                $readyRows++;
            }

            $rowHash = hash('sha256', json_encode($rawPayload));

            // Persist ImportRow
            $createdRow = ImportRow::create([
                'import_job_id' => $job->id,
                'sheet' => $mapping->sheet,
                'row_number' => $rowNum,
                'row_hash' => $rowHash,
                'raw_payload_json' => $rawPayload,
                'normalized_payload_json' => $normalizedPayload,
                'status' => $rowStatus,
                'action' => $action,
                'target_student_pk' => $targetStudentPk,
                'candidate_matches_json' => empty($candidateMatches) ? null : $candidateMatches,
                'diff_json' => $rowDiff ?? null,
            ]);

            // Persist Issues
            foreach ($rowIssues as $iss) {
                ImportRowIssue::create([
                    'import_row_id' => $createdRow->id,
                    'code' => $iss['code'],
                    'severity' => $iss['severity'],
                    'field' => $iss['field'] ?? null,
                    'source_column' => $iss['source_column'] ?? null,
                    'explanation_key' => $iss['explanation_key'],
                    'message_bn' => $iss['message_bn'],
                    'message_en' => $iss['message_en'],
                    'meta_json' => $iss['meta_json'] ?? null,
                ]);
            }
        }

        // Generate Cryptographic Preview Token (D08-F04-AT06)
        $previewToken = hash_hmac('sha256', "{$job->sha256}:{$job->mapping_version}:{$totalRows}", config('app.key'));
        $previewExpiresAt = now()->addHours(24);

        $nextState = ($invalidRows > 0 || $reviewRows > 0)
            ? ImportJobStatus::REVIEW_REQUIRED
            : ImportJobStatus::READY;

        $job->update([
            'total_rows' => $totalRows,
            'ready_rows' => $readyRows,
            'review_rows' => $reviewRows,
            'invalid_rows' => $invalidRows,
            'preview_token_hash' => $previewToken,
            'preview_expires_at' => $previewExpiresAt,
            'state' => $nextState,
        ]);

        ImportJobEvent::create([
            'import_job_id' => $job->id,
            'actor_id' => $actorId,
            'event' => 'VALIDATED',
            'before_state' => ImportJobStatus::VALIDATING->value,
            'after_state' => $nextState->value,
            'reason_code' => 'PREVIEW_VALIDATION_COMPLETED',
            'context_redacted_json' => [
                'total_rows' => $totalRows,
                'ready_rows' => $readyRows,
                'review_rows' => $reviewRows,
                'invalid_rows' => $invalidRows,
                'preview_token' => $previewToken,
            ],
            'created_at' => now(),
        ]);

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => $job->state->value,
            'total_rows' => $totalRows,
            'ready_rows' => $readyRows,
            'review_rows' => $reviewRows,
            'invalid_rows' => $invalidRows,
            'preview_token' => $previewToken,
            'preview_expires_at' => $previewExpiresAt->toIso8601String(),
        ];
    }

    /**
     * Get preview stats and check staleness (F04).
     */
    public function getPreview(ImportJob $job): array
    {
        $isStale = empty($job->preview_token_hash) || ($job->preview_expires_at && $job->preview_expires_at->isPast());

        return [
            'id' => $job->id,
            'job_number' => $job->job_number,
            'state' => $job->state->value,
            'total_rows' => $job->total_rows,
            'ready_rows' => $job->ready_rows,
            'review_rows' => $job->review_rows,
            'invalid_rows' => $job->invalid_rows,
            'preview_token' => $job->preview_token_hash,
            'preview_expires_at' => $job->preview_expires_at?->toIso8601String(),
            'is_stale' => $isStale,
        ];
    }

    /**
     * Paginated rows with filters (F04).
     */
    public function getRows(ImportJob $job, Request $request): LengthAwarePaginator
    {
        $query = ImportRow::with('issues')->where('import_job_id', $job->id);

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        if ($action = $request->query('action')) {
            $query->where('action', strtoupper($action));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereRaw("raw_payload_json::text ILIKE ?", ["%{$search}%"])
                  ->orWhereRaw("normalized_payload_json::text ILIKE ?", ["%{$search}%"]);
            });
        }

        $pageSize = min(100, max(1, (int)$request->query('per_page', 20)));
        return $query->orderBy('row_number', 'asc')->paginate($pageSize);
    }

    /**
     * Check if a string contains formula injection prefixes.
     */
    protected function detectFormulaInjection(string $val): bool
    {
        if (empty($val)) return false;

        $first = $val[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return true;
        }

        if ($first === "'" && strlen($val) > 1) {
            $second = $val[1];
            if (in_array($second, ['=', '+', '-', '@', "\t", "\r"], true)) {
                return true;
            }
        }

        return false;
    }
}
