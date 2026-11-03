<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group's submission for an assessment: one frozen PDF made from the group's documents at submit time. The PDF (on
 * the private disk) is the record; submission_documents only lists what went into it. Additive: nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pdf_path');
            $table->unsignedBigInteger('pdf_size')->default(0);
            $table->unsignedInteger('page_count')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['group_id', 'assessment_id']);
        });

        Schema::create('submission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_documents');
        Schema::dropIfExists('submissions');
    }
};
