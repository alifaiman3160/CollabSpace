<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each section turns a level into marks, and the total it declares.
 *
 * The weight column means different things in different tables (a multiplier in
 * one, the criterion's maximum in another), so the formula is stored on the
 * section and every total can say how it was reached. Null scoring means the
 * lecturer has not chosen yet; a section that the system suggests for cannot be
 * saved that way.
 *
 *   level_x_weight  marks = level marks x weight
 *   level_only      marks = level marks (the weight is the maximum)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_sections', function (Blueprint $table) {
            $table->decimal('declared_total', 8, 2)->nullable()->after('mode');
            $table->string('scoring', 16)->nullable()->after('declared_total');
        });
    }

    public function down(): void
    {
        Schema::table('rubric_sections', function (Blueprint $table) {
            $table->dropColumn(['declared_total', 'scoring']);
        });
    }
};
