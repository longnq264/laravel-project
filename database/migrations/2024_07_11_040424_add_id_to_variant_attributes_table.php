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
        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->dropColumn('id');
            $table->dropSoftDeletes();
        });
    }
};
