<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryGuardian = null;
        if ($this->relationLoaded('guardians')) {
            $primary = $this->guardians->first(fn ($g) => (bool) $g->pivot?->is_primary);
            if ($primary) {
                $primaryGuardian = new GuardianResource($primary);
            }
        } elseif ($this->relationLoaded('primaryStudentGuardian') && $this->primaryStudentGuardian?->guardian) {
            $g = $this->primaryStudentGuardian->guardian;
            $g->setRelation('pivot', $this->primaryStudentGuardian);
            $primaryGuardian = new GuardianResource($g);
        }

        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'gender' => $this->gender,
            'admission_date' => $this->admission_date?->format('Y-m-d'),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'birth_registration_number' => $this->birth_registration_number,
            'present_address_bn' => $this->present_address_bn,
            'present_address_en' => $this->present_address_en,
            'permanent_address_bn' => $this->permanent_address_bn,
            'permanent_address_en' => $this->permanent_address_en,
            'profile_photo_path' => $this->profile_photo_path,
            'primary_guardian' => $primaryGuardian,
            'guardians' => GuardianResource::collection($this->whenLoaded('guardians')),
            'identifiers' => StudentIdentifierResource::collection($this->whenLoaded('identifiers')),
            'documents_count' => $this->documents_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

