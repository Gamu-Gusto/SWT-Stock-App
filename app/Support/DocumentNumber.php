<?php

namespace App\Support;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Generates sequential, human-friendly document numbers such as SL-2026-000123.
 * Must be called inside the caller's transaction; the sequence row is locked until commit.
 */
final class DocumentNumber
{
    public static function next(string $prefix): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($prefix, $year) {
            DocumentSequence::query()->insertOrIgnore(['prefix' => $prefix, 'year' => $year, 'last_number' => 0]);
            $sequence = DocumentSequence::query()->where('prefix', $prefix)->where('year', $year)->lockForUpdate()->firstOrFail();
            $sequence->last_number++;
            $sequence->save();

            return sprintf('%s-%d-%06d', $prefix, $year, $sequence->last_number);
        });
    }
}
