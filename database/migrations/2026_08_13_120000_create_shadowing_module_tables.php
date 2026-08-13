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
        Schema::create('shadowing_lessons', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('code')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('level')->default('B1'); // A1, A2, B1, B2, C1
            $table->string('topic')->default('General');
            $table->string('audio_url')->nullable();
            $table->string('youtube_video_id')->nullable();
            $table->enum('media_type', ['audio', 'video', 'youtube'])->default('audio');
            $table->integer('total_segments')->default(0);
            $table->string('status', 32)->default('published');
            $table->text('raw_transcript')->nullable();
            $table->text('canonical_transcript')->nullable();
            $table->string('transcript_source', 32)->default('youtube_caption'); // youtube_caption, srt, vtt, manual, stt_fallback
            $table->integer('content_version')->default(1);
            $table->string('checksum')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'level']);
        });

        Schema::create('shadowing_segments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('shadowing_lesson_id')->constrained('shadowing_lessons')->cascadeOnDelete();
            $table->integer('segment_index');
            $table->integer('start_ms'); // Speech chunk start timestamp in milliseconds
            $table->integer('end_ms');   // Speech chunk end timestamp in milliseconds
            $table->text('transcript');
            $table->text('translation_vi')->nullable();
            $table->string('ipa')->nullable();
            $table->string('speaker')->nullable();
            $table->string('difficulty')->default('B1');
            $table->integer('loop_default')->default(2);
            $table->boolean('needs_review')->default(false); // Flag if split timestamp is uncertain
            $table->timestamps();

            $table->unique(['shadowing_lesson_id', 'segment_index']);
            $table->index(['shadowing_lesson_id', 'start_ms']);
        });

        Schema::create('user_shadowing_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shadowing_segment_id')->constrained('shadowing_segments')->cascadeOnDelete();
            $table->integer('content_version')->default(1);
            $table->string('mode')->default('LISTEN_REPEAT'); // LISTEN_REPEAT, SHADOWING, CHALLENGE
            $table->string('audio_recording_url')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->float('score')->default(0.0);
            $table->timestamps();

            $table->index(['user_id', 'shadowing_segment_id']);
        });

        Schema::create('user_shadowing_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shadowing_segment_id')->constrained('shadowing_segments')->cascadeOnDelete();
            $table->float('best_score')->default(0.0);
            $table->integer('practice_count')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('last_practiced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shadowing_segment_id'], 'usr_shd_prog_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_shadowing_progress');
        Schema::dropIfExists('user_shadowing_attempts');
        Schema::dropIfExists('shadowing_segments');
        Schema::dropIfExists('shadowing_lessons');
    }
};
