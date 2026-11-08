<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SlipVerificationTokenService
{
    /**
     * Generate an opaque signed verification token.
     * Returns ['token' => $token, 'hash' => $hash, 'encrypted' => $encrypted]
     */
    public function generateToken(string $type, int $id, string $number): array
    {
        $random = Str::random(32);
        $token = sprintf('v1.%s.%s', $type, $random);
        $hash = hash('sha256', $token);
        $encrypted = Crypt::encryptString(json_encode([
            'type' => $type,
            'id' => $id,
            'number' => $number,
            'created_at' => now()->toIso8601String(),
        ]));

        return [
            'token' => $token,
            'hash' => $hash,
            'encrypted' => $encrypted,
        ];
    }

    /**
     * Hash a token for lookup.
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mask name for privacy (e.g. "Md. Abdur Rahman" -> "Md. A*** R***" or Bengali "মোঃ আব্দুর রহমান" -> "মোঃ আ*** র***")
     */
    public function maskName(?string $name): string
    {
        if (!$name) {
            return '';
        }

        $parts = preg_split('/\s+/u', trim($name));
        $maskedParts = [];

        foreach ($parts as $part) {
            $len = mb_strlen($part, 'UTF-8');
            if ($len <= 2) {
                $maskedParts[] = $part;
            } else {
                $first = mb_substr($part, 0, 1, 'UTF-8');
                $maskedParts[] = $first . '***';
            }
        }

        return implode(' ', $maskedParts);
    }
}

