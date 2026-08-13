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
        Schema::table('user_shadowing_progress', function (Blueprint $table) {
            $table->string('mastery_status', 16)
                ->default('unseen')
                ->comment('unseen|practicing|needs_review|mastered');
            
            $table->index(['user_id', 'mastery_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_shadowing_progress', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'mastery_status']);
            $table->dropColumn('mastery_status');
        });
    }
};
