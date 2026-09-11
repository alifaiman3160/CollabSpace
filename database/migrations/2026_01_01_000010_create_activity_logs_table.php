<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->enum('activity_type', [
                'task_created',
                'task_completed',
                'message_sent',
                'file_uploaded',
                'document_created',
                'document_edited',
            ]);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedTinyInteger('weight')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['group_id', 'user_id']);
            $table->index(['group_id', 'activity_type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
