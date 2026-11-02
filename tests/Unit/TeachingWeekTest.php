<?php

namespace Tests\Unit;

use App\Models\Subject;
use Carbon\Carbon;
use Tests\TestCase;

/** Subject::currentWeek() and weekOf(): pure functions of dates, in the application's timezone. */
class TeachingWeekTest extends TestCase
{
    protected function subject(?string $start): Subject
    {
        return (new Subject)->forceFill(['semester_start_date' => $start]);
    }

    protected function at(string $dateTime): Carbon
    {
        return Carbon::parse($dateTime, 'Asia/Kuala_Lumpur');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
    }

    public function test_the_start_day_itself_is_week_one(): void
    {
        $this->assertSame(1, $this->subject('2026-10-06')->currentWeek($this->at('2026-10-06 00:00')));
        $this->assertSame(1, $this->subject('2026-10-06')->currentWeek($this->at('2026-10-06 23:59')));
    }

    public function test_the_seventh_day_is_still_week_one_and_the_eighth_is_week_two(): void
    {
        $subject = $this->subject('2026-10-06');

        $this->assertSame(1, $subject->currentWeek($this->at('2026-10-12 12:00')), 'Day 7 (the 12th) is the last day of Week 1.');
        $this->assertSame(2, $subject->currentWeek($this->at('2026-10-13 00:00')), 'Day 8 starts Week 2.');
        $this->assertSame(5, $subject->currentWeek($this->at('2026-11-03 09:00')));
    }

    public function test_it_is_null_before_the_start_and_without_a_start_date(): void
    {
        $this->assertNull($this->subject('2026-10-06')->currentWeek($this->at('2026-10-05 23:59')));
        $this->assertNull($this->subject(null)->currentWeek($this->at('2026-10-06')));
        $this->assertNull($this->subject(null)->weekOf($this->at('2026-10-06')));
        $this->assertNull($this->subject('2026-10-06')->weekOf($this->at('2026-09-01')));
    }

    public function test_week_twenty_is_the_last_shown_and_twenty_one_is_null(): void
    {
        $subject = $this->subject('2026-10-06');

        $this->assertSame(20, $subject->currentWeek($this->at('2026-10-06')->addDays(19 * 7)));
        $this->assertNull($subject->currentWeek($this->at('2026-10-06')->addDays(20 * 7)));
        $this->assertNull($subject->currentWeek($this->at('2027-06-01')));
    }

    public function test_week_of_a_date_has_no_upper_limit(): void
    {
        $subject = $this->subject('2026-10-06');

        $this->assertSame(21, $subject->weekOf($this->at('2026-10-06')->addDays(20 * 7)));
        $this->assertSame(53, $subject->weekOf($this->at('2026-10-06')->addDays(52 * 7)));
        $this->assertSame(12, $subject->weekOf($this->at('2026-12-22')));
    }

    public function test_it_counts_calendar_days_in_the_application_timezone(): void
    {
        $subject = $this->subject('2026-10-06');

        // 16:30 UTC on the 12th is 00:30 on the 13th in Kuala Lumpur: already Week 2 there.
        $this->assertSame(2, $subject->currentWeek(Carbon::parse('2026-10-12 16:30', 'UTC')));
        // 15:30 UTC on the 12th is 23:30 on the 12th in Kuala Lumpur: still Week 1.
        $this->assertSame(1, $subject->currentWeek(Carbon::parse('2026-10-12 15:30', 'UTC')));
        // 16:30 UTC on the 5th is 00:30 on the 6th: Week 1 has begun.
        $this->assertSame(1, $subject->currentWeek(Carbon::parse('2026-10-05 16:30', 'UTC')));
        $this->assertNull($subject->currentWeek(Carbon::parse('2026-10-05 15:30', 'UTC')));
    }

    public function test_it_uses_now_by_default(): void
    {
        Carbon::setTestNow($this->at('2026-10-20 10:00'));

        $this->assertSame(3, $this->subject('2026-10-06')->currentWeek());

        Carbon::setTestNow();
    }
}
