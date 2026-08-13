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
            $table->text('translation_error')->nullable()->after('translated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shadowing_sources', function (Blueprint $table) {
            $table->dropColumn('translation_error');
        });
    }
};
