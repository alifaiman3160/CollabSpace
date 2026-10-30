<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A suggestion can now say "there is not enough recorded activity in this group
 * to compare students": no level, the reason, and a confidence of none. The
 * lecturer decides that criterion unaided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mark_suggestions', function (Blueprint $table) {
            $table->boolean('insufficient_data')->default(false)->after('needs_input_reason');
            $table->string('confidence', 8)->nullable()->after('insufficient_data');   // normal | none
        });
    }

    public function down(): void
    {
        Schema::table('mark_suggestions', function (Blueprint $table) {
            $table->dropColumn(['insufficient_data', 'confidence']);
        });
    }
};