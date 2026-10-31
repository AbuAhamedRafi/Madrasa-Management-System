<?php

namespace App\Http\Resources\Students;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'type' => $this->type instanceof \BackedEnum ? $this->type->value : $this->type,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'verification_status' => $this->verification_status instanceof \BackedEnum
                ? $this->verification_status->value
                : $this->verification_status,
            'verification_reason' => $this->verification_reason,
            'uploaded_by' => $this->uploader ? [
                'id' => $this->uploader->id,
                'username' => $this->uploader->username,
                'name_bn' => $this->uploader->name_bn,
                'name_en' => $this->uploader->name_en,
            ] : null,
            'verified_by' => $this->verifier ? [
                'id' => $this->verifier->id,
                'username' => $this->verifier->username,
                'name_bn' => $this->verifier->name_bn,
                'name_en' => $this->verifier->name_en,
            ] : null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

