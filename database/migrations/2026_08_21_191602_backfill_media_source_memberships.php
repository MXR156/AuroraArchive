<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('media')
            ->whereNotNull('source_id')
            ->orderBy('source_id')
            ->orderBy('published_at')
            ->orderBy('id')
            ->get(['id', 'source_id'])
            ->groupBy('source_id')
            ->each(function ($media, int|string $sourceId): void {
                $now = now();
                DB::table('media_source')->insert($media->values()->map(fn (object $medium, int $position): array => [
                    'source_id' => $sourceId,
                    'media_id' => $medium->id,
                    'position' => $position + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('media_source')->truncate();
    }
};
