<?php

namespace App\Support\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A per-lecturer, per-day count of requests to the provider.
 *
 * Counted when a request is made, not when it succeeds: a provider that is
 * failing still costs time, and a loop that retries a failing call is exactly
 * what the cap is there to stop. Stored as a count only. Nothing about what was
 * asked or answered is kept here.
 */
class AiQuota
{
    public function limit(): int
    {
        return max(0, (int) config('ai.daily_limit', 0));
    }

    public function used(User $user): int
    {
        return (int) DB::table('ai_usage')
            ->where('user_id', $user->id)
            ->where('day', $this->today())
            ->value('requests');
    }

    public function remaining(User $user): int
    {
        return max(0, $this->limit() - $this->used($user));
    }

    /**
     * Take one request from today's allowance.
     *
     * @return bool  false when nothing was left, in which case nothing is counted
     */
    public function take(User $user): bool
    {
        $limit = $this->limit();

        return DB::transaction(function () use ($user, $limit): bool {
            $row = DB::table('ai_usage')
                ->where('user_id', $user->id)
                ->where('day', $this->today())
                ->lockForUpdate()
                ->first();

            if (($row->requests ?? 0) >= $limit) {
                return false;
            }

            if ($row) {
                DB::table('ai_usage')->where('id', $row->id)->increment('requests');
            } else {
                DB::table('ai_usage')->insert([
                    'user_id' => $user->id,
                    'day' => $this->today(),
                    'requests' => 1,
                ]);
            }

            return true;
        });
    }

    private function today(): string
    {
        return now()->toDateString();
    }
}
