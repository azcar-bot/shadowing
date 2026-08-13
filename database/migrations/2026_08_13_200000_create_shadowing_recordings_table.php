<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shadowing_recordings', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shadowing_lesson_id')->constrained('shadowing_lessons')->cascadeOnDelete();
            $table->foreignId('shadowing_segment_id')->constrained('shadowing_segments')->cascadeOnDelete();
            $table->string('disk')->default('media');
            $table->string('object_key', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            // Invariant: One active recording per user + lesson + segment
            $table->unique(['user_id', 'shadowing_lesson_id', 'shadowing_segment_id'], 'shadowing_recordings_user_lesson_segment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shadowing_recordings');
    }
};
