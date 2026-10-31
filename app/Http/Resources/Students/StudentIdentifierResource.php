<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentIdentifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'namespace' => $this->namespace,
            'value_normalized' => $this->value_normalized,
            'value_raw' => $this->value_raw,
            'issuer' => $this->issuer,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

