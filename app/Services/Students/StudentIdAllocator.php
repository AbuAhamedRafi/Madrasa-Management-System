<?php

namespace App\Services\Students;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class StudentIdAllocator
{
    public function allocate(string|DateTimeInterface|null $date = null): string
    {
        $carbon = $date ? Carbon::parse($date, 'Asia/Dhaka') : Carbon::now('Asia/Dhaka');
        
        $year2 = $carbon->format('y');
        $month = (int) $carbon->format('n');
        $term = $month <= 6 ? '01' : '02';
        $prefix = $year2 . $term;
        $namespace = 'STUDENT';

        if (DB::getDriverName() === 'pgsql') {
            $result = DB::selectOne("
                INSERT INTO student_id_sequences (namespace, prefix, last_value, created_at, updated_at)
                VALUES (:namespace, :prefix, 1, NOW(), NOW())
                ON CONFLICT (namespace, prefix)
                DO UPDATE SET last_value = student_id_sequences.last_value + 1, updated_at = NOW()
                RETURNING last_value
            ", [
                'namespace' => $namespace,
                'prefix' => $prefix,
            ]);

            $lastValue = $result->last_value;
        } else {
            // Portable transaction fallback
            $lastValue = DB::transaction(function () use ($namespace, $prefix) {
                $seq = DB::table('student_id_sequences')
                    ->where('namespace', $namespace)
                    ->where('prefix', $prefix)
                    ->lockForUpdate()
                    ->first();

                if ($seq) {
                    $next = $seq->last_value + 1;
                    DB::table('student_id_sequences')
                        ->where('id', $seq->id)
                        ->update(['last_value' => $next, 'updated_at' => now()]);
                    return $next;
                } else {
                    DB::table('student_id_sequences')->insert([
                        'namespace' => $namespace,
                        'prefix' => $prefix,
                        'last_value' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    return 1;
                }
            });
        }

        return $prefix . $lastValue;
    }
}

