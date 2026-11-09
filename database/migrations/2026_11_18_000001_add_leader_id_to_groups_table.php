<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each group has one leader, a current member of it. Adds groups.leader_id and fills it once for the groups that exist:
 * the member with the highest CGPA, a tie or a missing CGPA broken by the lowest user id. Groups with no members stay
 * empty. Safe to run again: only groups with no leader are touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('groups', 'leader_id')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->foreignId('leader_id')->nullable()->after('section_id')->constrained('users')->nullOnDelete();
            });
        }

        DB::table('groups')->whereNull('leader_id')->orderBy('id')->pluck('id')->each(function ($groupId) {
            $leader = DB::table('group_members')
                ->join('users', 'users.id', '=', 'group_members.user_id')
                ->where('group_members.group_id', $groupId)
                ->orderByRaw('users.cgpa is null')
                ->orderByDesc('users.cgpa')
                ->orderBy('users.id')
                ->value('users.id');

            if ($leader !== null) {
                DB::table('groups')->where('id', $groupId)->update(['leader_id' => $leader]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('groups', 'leader_id')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropConstrainedForeignId('leader_id');
            });
        }
    }
};
