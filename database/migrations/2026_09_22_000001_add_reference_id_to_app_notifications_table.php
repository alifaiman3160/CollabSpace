<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a notification say which record it is about.
     *
     * The deadline reminders run on a schedule, so without this the same task
     * would be announced again every single day until somebody dealt with it.
     * Matching on the message text instead would be fragile, and matching on
     * the link is too coarse — every task in a group shares one board link.
     *
     * Nullable, and a plain column rather than a foreign key: it points at
     * whichever table the notification's type implies, exactly like
     * activity_logs.reference_id. The dedupe always pairs it with the type.
     */
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('reference_id')->nullable()->after('type');

            // The "have we already told this person about this?" lookup.
            $table->index(['user_id', 'type', 'reference_id'], 'app_notifications_dedupe_index');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropIndex('app_notifications_dedupe_index');
            $table->dropColumn('reference_id');
        });
    }
};
