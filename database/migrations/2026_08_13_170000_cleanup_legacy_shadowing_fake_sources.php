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
        $forbiddenSources = ['ai_generated_fallback', 'mock', 'demo', 'sample', 'fake', 'prototype'];

        // 1. Mark fake/mock sources as invalidated
        $invalidSourceIds = DB::table('shadowing_sources')
            ->whereNull('transcript_source')
            ->orWhereIn(DB::raw('LOWER(transcript_source)'), $forbiddenSources)
            ->pluck('id')
            ->toArray();

        if (!empty($invalidSourceIds)) {
            DB::table('shadowing_sources')
                ->whereIn('id', $invalidSourceIds)
                ->update(['status' => 'invalidated']);
        }

        // 2. Mark YouTube lessons linked to invalid sources OR YouTube lessons with source_id = NULL as archived
        DB::table('shadowing_lessons')
            ->where('media_type', 'youtube')
            ->where(function ($q) use ($invalidSourceIds) {
                $q->whereNull('source_id');
                if (!empty($invalidSourceIds)) {
                    $q->orWhereIn('source_id', $invalidSourceIds);
                }
            })
            ->update(['status' => 'archived']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op for cleanup migration
    }
};
