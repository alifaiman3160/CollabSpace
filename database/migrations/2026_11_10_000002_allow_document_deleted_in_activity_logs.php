<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activity_type was an enum of six values; a seventh, document_deleted (the audit trail, worth no points and not
 * evidence), needs room. Turning the column into a short string keeps every existing value and row as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('activity_type', 40)->change();
        });
    }

    public function down(): void
    {
        // The trail rows cannot live in the old enum.
        DB::table('activity_logs')->where('activity_type', 'document_deleted')->delete();

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->enum('activity_type', ['task_created', 'task_completed', 'message_sent', 'file_uploaded', 'document_created', 'document_edited'])->change();
        });
    }
};