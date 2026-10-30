<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A student's CGPA, typed in by the administrator.
     *
     * Entered by hand and nothing else. CollabSpace has no connection to any
     * academic system, so this is only as current as the last time somebody
     * edited the account, and every screen that shows it says so. It exists for
     * one purpose: giving a lecturer something to balance groups on.
     *
     * Nullable, because most accounts will not have one at first and a missing
     * figure has to be a state the rest of the system copes with rather than a
     * reason to refuse creating a student. decimal(3,2) holds 0.00 to 9.99; the
     * 0 to 4 range is enforced where it is entered, not here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('cgpa', 3, 2)->nullable()->after('matric_no');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cgpa');
        });
    }
};
