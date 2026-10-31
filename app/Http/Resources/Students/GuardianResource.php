<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'name_bn' => $this->name_bn,
            'name_en' => $this->name_en,
            'phone' => $this->phone,
            'alternative_phone' => $this->alternative_phone,
            'address_bn' => $this->address_bn,
            'address_en' => $this->address_en,
            'occupation' => $this->occupation,
        ];

        if ($this->pivot) {
            $data['relation'] = $this->pivot->relation instanceof \BackedEnum
                ? $this->pivot->relation->value
                : $this->pivot->relation;
            $data['is_primary'] = (bool) $this->pivot->is_primary;
            $data['sms_consent'] = (bool) $this->pivot->sms_consent;

            $consentAt = $this->pivot->consent_at;
            if ($consentAt instanceof \DateTimeInterface) {
                $data['consent_at'] = $consentAt->toIso8601String();
            } elseif (is_string($consentAt) && !empty($consentAt)) {
                $data['consent_at'] = \Carbon\Carbon::parse($consentAt)->toIso8601String();
            } else {
                $data['consent_at'] = null;
            }

            $data['consent_source'] = $this->pivot->consent_source;
        }

        return $data;
    }
}

