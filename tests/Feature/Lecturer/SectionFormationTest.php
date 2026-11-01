<?php

namespace Tests\Feature\Lecturer;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\User;
use App\Support\Formation\FormationException;
use App\Support\Formation\GroupFormation;
use App\Support\Formation\Pool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/**
 * Groups never mix sections: formation runs one section at a time, manual assignment offers and accepts only the
 * group's own section, and the guard that writes groups refuses a mixed plan.
 *
 * Section 1A has the usual cohort (Student A1..A8); section 1B has Student B1..B4.
 */
class SectionFormationTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected Section $sectionB;

    /** @var \Illuminate\Support\Collection<int, User> */
    protected $bStudents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCohort(4, [3.9, 3.7, 3.5, 3.3, 3.1, 2.9, 2.7, 2.5]);
        $this->sectionB = Section::create(['subject_id' => $this->subject->id, 'name' => '1B']);

        $this->bStudents = $this->makeStudents([3.8, 3.4, 3.0, 2.6], 'B');
        $this->bStudents->each(fn (User $s) => $this->enrol($s, $this->sectionB));
    }

    protected function ids($users): array
    {
        return collect($users)->pluck('id')->all();
    }

    /*
    | The pool
    */

    public function test_the_pool_is_the_chosen_sections_students_without_a_group(): void
    {
        $this->makeGroup('Taken', [$this->cohort[0]]);

        $a = Pool::for($this->subject, $this->section);
        $b = Pool::for($this->subject, $this->sectionB);

        $this->assertSame(7, $a->count(), 'Eight in 1A, one already grouped.');
        $this->assertEmpty(array_intersect($this->ids($a), $this->ids($this->bStudents)));
        $this->assertEqualsCanonicalizing($this->ids($this->bStudents), $this->ids($b));
        $this->assertSame(0, Pool::for($this->subject, null)->count());
    }

    public function test_the_formation_page_lists_only_the_chosen_section(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', [$this->subject, 'section' => $this->section->id]))->assertOk()->getContent();

        $this->assertStringContainsString('Student A1', $html);
        $this->assertStringNotContainsString('Student B1', $html);
        $this->assertStringContainsString('Section 1A', $html);
        $this->assertStringContainsString('data-section-picker', $html);
        $this->assertStringContainsString('name="section" value="'.$this->section->id.'"', $html);

        $other = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', [$this->subject, 'section' => $this->sectionB->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Student B1', $other);
        $this->assertStringNotContainsString('Student A1', $other);
    }

    public function test_with_several_sections_the_page_asks_for_one_first(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.formation', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('data-choose-section', $html);
        $this->assertStringNotContainsString('Student A1', $html);
        $this->assertStringNotContainsString('Student B1', $html);
        $this->assertStringNotContainsString('id="balanced-form"', $html);
    }

    public function test_a_section_of_another_subject_or_lecturer_is_not_found(): void
    {
        $other = User::factory()->lecturer()->create(['must_change_password' => false]);
        $theirs = \App\Models\Subject::create(['lecturer_id' => $other->id, 'code' => 'OTH1', 'name' => 'Other', 'group_size' => 4]);
        $foreign = Section::create(['subject_id' => $theirs->id, 'name' => '9Z']);

        $this->actingAs($this->lecturer)->get(route('lecturer.formation', [$this->subject, 'section' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->lecturer)->get(route('lecturer.formation', [$theirs, 'section' => $foreign->id]))->assertNotFound();
        $this->actingAs($other)->get(route('lecturer.formation', [$this->subject, 'section' => $this->section->id]))->assertNotFound();
    }

    public function test_a_subject_with_a_single_section_needs_no_choosing(): void
    {
        $this->sectionB->delete();

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.formation', $this->subject))->assertOk()->getContent();

        $this->assertStringContainsString('Student A1', $html);
    }

    /*
    | Balanced
    */

    public function test_balanced_formation_for_one_section_never_touches_the_other(): void
    {
        $everyone = array_merge($this->ids($this->cohort), $this->ids($this->bStudents));

        $response = $this->actingAs($this->lecturer)->post(route('lecturer.formation.balanced.preview', $this->subject), [
            'section' => $this->section->id, 'students' => $everyone, 'groups' => 2, 'prefix' => 'Team',
        ]);

        $response->assertSessionHasNoErrors();
        $token = $this->tokenFrom($response);

        $this->actingAs($this->lecturer)->post(route('lecturer.formation.balanced.confirm', [$this->subject, $token]))->assertSessionHasNoErrors();

        $groups = Group::where('subject_id', $this->subject->id)->get();
        $this->assertCount(2, $groups);
        $this->assertSame([$this->section->id], $groups->pluck('section_id')->unique()->values()->all());

        $members = GroupMember::whereIn('group_id', $groups->pluck('id'))->pluck('user_id')->all();
        $this->assertCount(8, $members, 'Only the 1A students were placed; the 1B ids sent were dropped.');
        $this->assertEmpty(array_intersect($members, $this->ids($this->bStudents)));
        $this->assertSame(0, GroupMember::whereIn('user_id', $this->ids($this->bStudents))->count());
    }

    public function test_balanced_preview_needs_a_section_when_there_are_several(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.formation.balanced.preview', $this->subject), [
            'students' => $this->ids($this->cohort), 'groups' => 2,
        ])->assertSessionHasErrors('students');

        $this->assertSame(0, Group::count());
    }

    public function test_the_second_section_gets_its_own_groups_with_its_own_section(): void
    {
        $response = $this->actingAs($this->lecturer)->post(route('lecturer.formation.balanced.preview', $this->subject), [
            'section' => $this->sectionB->id, 'students' => $this->ids($this->bStudents), 'groups' => 2, 'prefix' => 'B team',
        ]);
        $this->actingAs($this->lecturer)->post(route('lecturer.formation.balanced.confirm', [$this->subject, $this->tokenFrom($response)]));

        $this->assertSame([$this->sectionB->id], Group::pluck('section_id')->unique()->values()->all());
        $this->assertSame(4, GroupMember::count());
    }

    /*
    | The guard that writes groups
    */

    protected function plan(string $name, array $members): array
    {
        return ['name' => $name, 'size' => 4, 'member_ids' => $this->ids($members), 'meta' => []];
    }

    public function test_a_plan_that_mixes_sections_is_refused_and_nothing_is_saved(): void
    {
        $this->expectException(FormationException::class);
        $this->expectExceptionMessage('mix students of different sections');

        try {
            app(GroupFormation::class)->create($this->subject, $this->lecturer, Group::FORMED_BALANCED, [
                $this->plan('Fine', [$this->cohort[0], $this->cohort[1]]),
                $this->plan('Mixed', [$this->cohort[2], $this->bStudents[0]]),
            ]);
        } finally {
            $this->assertSame(0, Group::count());
            $this->assertSame(0, GroupMember::count());
        }
    }

    public function test_a_chosen_section_rejects_a_student_of_another(): void
    {
        $this->expectException(FormationException::class);
        $this->expectExceptionMessage('is in section 1B, not 1A');

        app(GroupFormation::class)->create($this->subject, $this->lecturer, Group::FORMED_BALANCED, [
            $this->plan('Pair', [$this->cohort[0], $this->bStudents[0]]),
        ], $this->section);
    }

    public function test_a_student_who_is_not_enrolled_is_refused(): void
    {
        $stranger = User::factory()->student()->create(['name' => 'Stranger']);

        $this->expectException(FormationException::class);
        $this->expectExceptionMessage('Stranger is not enrolled');

        app(GroupFormation::class)->create($this->subject, $this->lecturer, Group::FORMED_BALANCED, [
            $this->plan('Pair', [$this->cohort[0], $stranger]),
        ]);
    }

    public function test_each_group_takes_the_section_of_its_members(): void
    {
        $groups = app(GroupFormation::class)->create($this->subject, $this->lecturer, Group::FORMED_BALANCED, [
            $this->plan('Alpha', [$this->cohort[0], $this->cohort[1]]),
            $this->plan('Beta', [$this->bStudents[0], $this->bStudents[1]]),
        ]);

        $this->assertSame([$this->section->id, $this->sectionB->id], collect($groups)->pluck('section_id')->all());
    }

    /*
    | Manual assignment
    */

    public function test_the_dropdown_offers_only_the_groups_own_section(): void
    {
        $group = $this->makeGroup('G1', [$this->cohort[0]]);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.groups.show', $group))->assertOk()->getContent();

        $this->assertStringContainsString('Student A2', $html);
        $this->assertStringNotContainsString('Student B1', $html);
        $this->assertStringContainsString('Section 1A', $html);
    }

    public function test_a_student_of_another_section_is_refused_on_the_server(): void
    {
        $group = $this->makeGroup('G1', [$this->cohort[0]]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $this->bStudents[0]->id])
            ->assertSessionHasErrors('user_id');

        $this->assertStringContainsString('section 1B', session('errors')->first('user_id'));
        $this->assertSame(1, $group->groupMembers()->count());
    }

    public function test_a_student_who_is_not_enrolled_in_the_subject_is_refused(): void
    {
        $group = $this->makeGroup('G1', [$this->cohort[0]]);
        $stranger = User::factory()->student()->create();

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');

        $this->assertStringContainsString('not enrolled', session('errors')->first('user_id'));
    }

    public function test_a_student_of_the_same_section_is_added(): void
    {
        $group = $this->makeGroup('G1', [$this->cohort[0]]);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $this->cohort[1]->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $group->groupMembers()->count());
    }

    public function test_a_group_without_a_section_takes_the_section_of_its_first_member(): void
    {
        $group = Group::create(['subject_id' => $this->subject->id, 'name' => 'Empty', 'group_size' => 4]);
        $this->assertNull($group->section_id);

        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $this->bStudents[0]->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->sectionB->id, $group->fresh()->section_id);

        // ...and from then on it is a 1B group.
        $this->actingAs($this->lecturer)
            ->post(route('lecturer.groups.members.store', $group), ['user_id' => $this->cohort[0]->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_creating_a_group_by_hand_can_name_its_section(): void
    {
        $this->actingAs($this->lecturer)->post(route('lecturer.groups.store'), [
            'subject_id' => $this->subject->id, 'section_id' => $this->sectionB->id, 'name' => 'B hand', 'group_size' => 4,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->sectionB->id, Group::where('name', 'B hand')->value('section_id'));

        // Another subject's section is not acceptable.
        $other = \App\Models\Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'ZZZ1', 'name' => 'Z', 'group_size' => 4]);
        $foreign = Section::create(['subject_id' => $other->id, 'name' => 'Q']);

        $this->actingAs($this->lecturer)->post(route('lecturer.groups.store'), [
            'subject_id' => $this->subject->id, 'section_id' => $foreign->id, 'name' => 'Bad', 'group_size' => 4,
        ])->assertSessionHasErrors('section_id');
    }

    /*
    | Import
    */

    public function test_an_import_reports_students_of_other_sections_as_not_in_this_section(): void
    {
        $csv = "Timestamp,Group name,Member 1,Member 2\n2026-10-01 09:00:00,Team Mixed,A21EC0001,B21EC0001\n2026-10-01 09:00:00,Team Clean,A21EC0002,A21EC0003\n";

        $upload = $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'section' => $this->section->id, 'layout' => 'group_rows',
            'file' => UploadedFile::fake()->createWithContent('r.csv', $csv),
        ]);
        $token = basename(dirname(parse_url($upload->headers->get('Location'), PHP_URL_PATH)));

        $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.map', [$this->subject, $token]), ['roles' => ['ignore', 'group', 'member', 'member']]);

        $html = $this->actingAs($this->lecturer)->get(route('lecturer.formation.import.review', [$this->subject, $token]))->assertOk()->getContent();

        $this->assertStringContainsString('Not in this section', $html);
        $this->assertStringContainsString('enrolled in section 1B', $html);

        $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.confirm', [$this->subject, $token]), ['mode' => 'clean']);

        $this->assertSame(1, Group::count(), 'Only the clean group was created.');
        $this->assertSame($this->section->id, Group::first()->section_id);
        $this->assertSame(0, GroupMember::whereIn('user_id', $this->ids($this->bStudents))->count());
    }

    public function test_an_import_needs_a_section_when_there_are_several(): void
    {
        $this->actingAs($this->lecturer)->get(route('lecturer.formation.import', $this->subject))
            ->assertRedirect(route('lecturer.formation', $this->subject));

        $this->actingAs($this->lecturer)->get(route('lecturer.formation.import', [$this->subject, 'section' => $this->sectionB->id]))
            ->assertOk()->assertSee('Section 1B');
    }

    public function test_enrolments_survive_deleting_a_group(): void
    {
        $group = $this->makeGroup('Gone', [$this->cohort[0]]);

        $this->actingAs($this->lecturer)->delete(route('lecturer.groups.destroy', $group));

        $this->assertSame(12, Enrolment::count());
    }
}
