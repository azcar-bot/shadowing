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
        Schema::table('shadowing_sources', function (Blueprint $table) {
            $table->string('translation_status', 32)->default('pending')->after('status'); // pending, processing, completed, failed
            $table->string('translation_provider', 64)->nullable()->after('translation_status');
            $table->string('translation_model', 64)->nullable()->after('translation_provider');
            $table->string('translation_version', 32)->default('vi-v1')->after('translation_model');
            $table->timestamp('translated_at')->nullable()->after('translation_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shadowing_sources', function (Blueprint $table) {
            $table->dropColumn([
                'translation_status',
                'translation_provider',
                'translation_model',
                'translation_version',
                'translated_at',
            ]);
        });
    }
};
