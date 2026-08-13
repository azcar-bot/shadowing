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
        Schema::create('shadowing_sources', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->string('youtube_video_id', 32);
            $table->string('title')->default('Untitled Video');
            $table->integer('duration_seconds')->default(0);
            $table->string('transcript_source', 32)->default('youtube_manual_caption'); // youtube_manual_caption, youtube_auto_caption, deepgram_nova3
            $table->string('processing_version', 32)->default('natural-chunk-v1');
            $table->string('status', 32)->default('completed'); // pending, processing, completed, failed
            $table->json('raw_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['youtube_video_id', 'processing_version'], 'shd_src_yt_ver_unique');
            $table->index('youtube_video_id');
        });

        Schema::create('shadowing_source_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('shadowing_source_id')->constrained('shadowing_sources')->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->integer('start_ms');
            $table->integer('end_ms');
            $table->text('transcript');
            $table->text('translation_vi')->nullable();
            $table->string('ipa')->nullable();
            $table->string('speaker')->nullable();
            $table->float('quality_score')->default(1.0);
            $table->boolean('needs_review')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['shadowing_source_id', 'chunk_index'], 'shd_src_chk_idx_unique');
            $table->index(['shadowing_source_id', 'start_ms']);
        });

        Schema::table('shadowing_lessons', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('id')->constrained('shadowing_sources')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
            $table->string('visibility', 16)->default('official')->after('status'); // official, private
            $table->boolean('is_official')->default(true)->after('visibility');

            $table->index(['user_id', 'visibility']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shadowing_lessons', function (Blueprint $table) {
            $table->dropForeign(['source_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['source_id', 'user_id', 'visibility', 'is_official']);
        });

        Schema::dropIfExists('shadowing_source_chunks');
        Schema::dropIfExists('shadowing_sources');
    }
};
