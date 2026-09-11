<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edited_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->longText('content');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
