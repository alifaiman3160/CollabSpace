<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'lecturer', 'student'])->default('student')->after('password');
            $table->string('matric_no', 20)->nullable()->unique()->after('role');
            $table->boolean('must_change_password')->default(true)->after('matric_no');
            $table->boolean('is_active')->default(true)->after('must_change_password');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'matric_no', 'must_change_password', 'is_active']);
        });
    }
};
