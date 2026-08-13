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
        Schema::table('user_shadowing_attempts', function (Blueprint $table) {
            $table->float('score')->nullable()->default(null)->change();
        });

        Schema::table('user_shadowing_progress', function (Blueprint $table) {
            $table->float('best_score')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_shadowing_attempts', function (Blueprint $table) {
            $table->float('score')->default(0.0)->change();
        });

        Schema::table('user_shadowing_progress', function (Blueprint $table) {
            $table->float('best_score')->default(0.0)->change();
        });
    }
};
