<?php

namespace App\Repositories;

use App\Models\IdSequence;
use Illuminate\Support\Facades\DB;

class IdSequenceRepository
{
    /**
     * Atomically increment and return the next formatted sequence identifier.
     */
    public function nextPublicId(string $scope, string $prefix = 'usr_', int $padding = 3): string
    {
        return DB::transaction(function () use ($scope, $prefix, $padding) {
            $sequence = IdSequence::lockForUpdate()->find($scope);

            if (! $sequence) {
                $sequence = IdSequence::create([
                    'scope' => $scope,
                    'prefix' => $prefix,
                    'current_value' => 1,
                    'padding' => $padding,
                ]);

                return $prefix . str_pad('1', $padding, '0', STR_PAD_LEFT);
            }

            $sequence->current_value += 1;
            $sequence->save();

            return $prefix . str_pad((string) $sequence->current_value, $padding, '0', STR_PAD_LEFT);
        });
    }
}

