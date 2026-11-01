<?php

namespace Tests\Feature\Sections;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use App\Support\Sections\Backfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The migration's backfill: data that predates sections keeps working, and running it twice changes nothing more. */
class BackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function legacy(): array
    {
        $lecturer = User::factory()->lecturer()->create();
        $a = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'AAA1111', 'name' => 'A', 'group_size' => 4]);
        $b = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'BBB2222', 'name' => 'B', 'group_size' => 4]);

        $one = Group::create(['subject_id' => $a->id, 'name' => 'G1', 'group_size' => 4]);
        $two = Group::create(['subject_id' => $a->id, 'name' => 'G2', 'group_size' => 4]);
        $students = User::factory()->count(3)->student()->create();

        GroupMember::create(['group_id' => $one->id, 'user_id' => $students[0]->id, 'joined_at' => now()]);
        GroupMember::create(['group_id' => $one->id, 'user_id' => $students[1]->id, 'joined_at' => now()]);
        GroupMember::create(['group_id' => $two->id, 'user_id' => $students[2]->id, 'joined_at' => now()]);

        return compact('a', 'b', 'one', 'two', 'students');
    }

    public function test_every_subject_gets_a_default_section_and_its_groups_move_into_it(): void
    {
        ['a' => $a, 'b' => $b, 'one' => $one, 'two' => $two] = $this->legacy();

        Backfill::run();

        $this->assertSame('Main', Section::where('subject_id', $a->id)->sole()->name);
        $this->assertSame('Main', Section::where('subject_id', $b->id)->sole()->name, 'Even a subject with no groups.');
        $this->assertSame(Section::where('subject_id', $a->id)->value('id'), $one->fresh()->section_id);
        $this->assertSame(Section::where('subject_id', $a->id)->value('id'), $two->fresh()->section_id);
    }

    public function test_every_student_already_in_a_group_is_enrolled_in_that_subject(): void
    {
        ['a' => $a, 'b' => $b, 'students' => $students] = $this->legacy();

        Backfill::run();

        $main = Section::where('subject_id', $a->id)->value('id');

        foreach ($students as $student) {
            $this->assertDatabaseHas('enrolments', ['user_id' => $student->id, 'subject_id' => $a->id, 'section_id' => $main]);
        }

        $this->assertSame(0, Enrolment::where('subject_id', $b->id)->count());
        $this->assertSame(3, Enrolment::count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->legacy();

        Backfill::run();
        $counts = [Section::count(), Enrolment::count(), Group::whereNull('section_id')->count()];

        Backfill::run();
        Backfill::run();

        $this->assertSame($counts, [Section::count(), Enrolment::count(), Group::whereNull('section_id')->count()]);
        $this->assertSame([2, 3, 0], $counts);
    }

    public function test_it_never_moves_a_student_or_group_that_already_has_a_section(): void
    {
        ['a' => $a, 'one' => $one, 'students' => $students] = $this->legacy();

        $oneB = Section::create(['subject_id' => $a->id, 'name' => '1B']);
        $one->update(['section_id' => $oneB->id]);
        Enrolment::create(['user_id' => $students[0]->id, 'subject_id' => $a->id, 'section_id' => $oneB->id]);

        Backfill::run();

        $this->assertSame($oneB->id, $one->fresh()->section_id);
        $this->assertSame($oneB->id, Enrolment::where('user_id', $students[0]->id)->value('section_id'));
        $this->assertSame(1, Enrolment::where('user_id', $students[0]->id)->count());
        // The subject already had a section, so no "Main" was invented for it.
        $this->assertSame(0, Section::where('subject_id', $a->id)->where('name', 'Main')->count());
    }

    public function test_a_student_is_enrolled_in_the_section_of_the_group_they_are_in(): void
    {
        ['a' => $a, 'one' => $one, 'two' => $two, 'students' => $students] = $this->legacy();

        $x = Section::create(['subject_id' => $a->id, 'name' => '1A']);
        $y = Section::create(['subject_id' => $a->id, 'name' => '1B']);
        $one->update(['section_id' => $x->id]);
        $two->update(['section_id' => $y->id]);

        Backfill::run();

        $this->assertSame($x->id, Enrolment::where('user_id', $students[0]->id)->value('section_id'));
        $this->assertSame($y->id, Enrolment::where('user_id', $students[2]->id)->value('section_id'));
    }

    public function test_one_section_per_subject_per_student_is_enforced_by_the_table(): void
    {
        ['a' => $a, 'students' => $students] = $this->legacy();
        $x = Section::create(['subject_id' => $a->id, 'name' => '1A']);
        $y = Section::create(['subject_id' => $a->id, 'name' => '1B']);

        Enrolment::create(['user_id' => $students[0]->id, 'subject_id' => $a->id, 'section_id' => $x->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Enrolment::create(['user_id' => $students[0]->id, 'subject_id' => $a->id, 'section_id' => $y->id]);
    }
}
