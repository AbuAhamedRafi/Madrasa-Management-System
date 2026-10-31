<?php

namespace App\Services\Students;

use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DuplicateDetectionService
{
    public function check(array $data): array
    {
        $matches = collect();

        // 1. Exact Match on Normalized BRC
        if (!empty($data['birth_registration_number'])) {
            $normalizedBrc = preg_replace('/[^0-9]/', '', $data['birth_registration_number']);
            $brcHash = hash('sha256', $normalizedBrc);

            $exact = Student::where('birth_registration_hash', $brcHash)->first();
            if ($exact) {
                return [
                    'exact_match' => true,
                    'is_blocked' => true,
                    'requires_review' => false,
                    'matches' => [
                        [
                            'student_id' => $exact->student_id,
                            'name_bn' => $exact->name_bn,
                            'name_en' => $exact->name_en,
                            'match_reasons' => ['EXACT_BIRTH_REGISTRATION_NUMBER'],
                            'confidence' => 1.0,
                        ],
                    ],
                ];
            }
        }

        // 2. Candidate Matching
        $dob = !empty($data['date_of_birth']) ? Carbon::parse($data['date_of_birth'])->format('Y-m-d') : null;
        $nameBn = !empty($data['name_bn']) ? trim($data['name_bn']) : null;
        $nameEn = !empty($data['name_en']) ? trim($data['name_en']) : null;
        $guardianPhone = !empty($data['guardian_phone']) ? preg_replace('/[^0-9+]/', '', trim($data['guardian_phone'])) : null;

        $query = Student::with('guardians');

        // Query candidates by DOB or Guardian Phone
        $candidates = $query->where(function ($q) use ($dob, $guardianPhone) {
            if ($dob) {
                $q->where('date_of_birth', $dob);
            }
            if ($guardianPhone) {
                $q->orWhereHas('guardians', function ($gq) use ($guardianPhone) {
                    $gq->where('phone', $guardianPhone);
                });
            }
        })->limit(10)->get();

        foreach ($candidates as $candidate) {
            $reasons = [];
            $score = 0.0;

            $sameDob = $dob && $candidate->date_of_birth?->format('Y-m-d') === $dob;
            $hasSamePhone = false;
            if ($guardianPhone) {
                $hasSamePhone = $candidate->guardians->contains(fn ($g) => $g->phone === $guardianPhone);
            }

            $nameSimilarity = 0;
            if ($nameEn && $candidate->name_en) {
                similar_text(strtolower($nameEn), strtolower($candidate->name_en), $nameSimilarity);
            }

            // Case A: Exact DOB + High Name Similarity
            if ($sameDob && $nameSimilarity >= 75) {
                $reasons[] = 'NAME_AND_DOB_MATCH';
                $score += 0.8;
            }

            // Case B: Same Guardian Phone + Exact DOB
            if ($sameDob && $hasSamePhone) {
                $reasons[] = 'DOB_AND_GUARDIAN_PHONE_MATCH';
                $score += 0.85;
            }

            // Case C: Same Guardian Phone + High Name Similarity
            if ($hasSamePhone && $nameSimilarity >= 75) {
                $reasons[] = 'NAME_AND_GUARDIAN_PHONE_MATCH';
                $score += 0.75;
            }

            // Note: If only guardian phone matches but DOB and Name are completely different, it's a sibling, NOT a duplicate!
            if ($hasSamePhone && !$sameDob && $nameSimilarity < 50) {
                continue;
            }

            if (!empty($reasons)) {
                $matches->push([
                    'student_id' => $candidate->student_id,
                    'name_bn' => $candidate->name_bn,
                    'name_en' => $candidate->name_en,
                    'date_of_birth' => $candidate->date_of_birth?->format('Y-m-d'),
                    'match_reasons' => $reasons,
                    'confidence' => min($score, 0.99),
                ]);
            }
        }

        $hasDuplicates = $matches->isNotEmpty();

        return [
            'exact_match' => false,
            'is_blocked' => false,
            'has_potential_duplicate' => $hasDuplicates,
            'requires_review' => $hasDuplicates,
            'matches' => $matches->all(),
        ];
    }
}

