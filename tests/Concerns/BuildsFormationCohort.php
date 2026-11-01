<?php

namespace Tests\Concerns;

use App\Models\Enrolment;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Section;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A lecturer, a subject (with one section, "1A") and a cohort of students enrolled in it, for the formation tests.
 */
trait BuildsFormationCohort
{
    protected User $lecturer;

    protected Subject $subject;

    protected Section $section;

    /** @var Collection<int, User> */
    protected Collection $cohort;

    /** Thirteen CGPAs from strong to weak: the awkward headcount for four groups. */
    protected array $cgpas = [3.95, 3.88, 3.75, 3.60, 3.42, 3.30, 3.15, 3.02, 2.90, 2.74, 2.55, 2.40, 2.10];

    protected function buildCohort(int $limit = 4, ?array $cgpas = null): void
    {
        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => $limit,
        ]);

        $this->section = Section::create(['subject_id' => $this->subject->id, 'name' => '1A']);

        $this->cohort = $this->makeStudents($cgpas ?? $this->cgpas);
    }

    /**
     * @param  list<float|null>  $cgpas
     * @return Collection<int, User>
     */
    protected function makeStudents(array $cgpas, string $tag = 'A'): Collection
    {
        return collect($cgpas)->map(function ($cgpa, $i) use ($tag) {
            $student = User::factory()->student()->create([
                'name' => 'Student '.$tag.($i + 1),
                'email' => strtolower($tag).($i + 1).'@student.test',
                'matric_no' => sprintf('%s21EC%04d', $tag, $i + 1),
                'cgpa' => $cgpa,
                'must_change_password' => false,
            ]);

            $this->enrol($student);

            return $student;
        });
    }

    /** Enrols a student in the cohort's subject, in the given section (default: the cohort's "1A"). */
    protected function enrol(User $student, ?Section $section = null, ?Subject $subject = null): Enrolment
    {
        $subject ??= $this->subject;
        $section ??= $this->section;

        return Enrolment::updateOrCreate(
            ['user_id' => $student->id, 'subject_id' => $subject->id],
            ['section_id' => $section->id],
        );
    }

    protected function makeGroup(string $name, array $members = [], int $size = 4): Group
    {
        $group = Group::create(['subject_id' => $this->subject->id, 'section_id' => $this->section->id, 'name' => $name, 'group_size' => $size]);

        foreach ($members as $member) {
            GroupMember::create(['group_id' => $group->id, 'user_id' => $member->id, 'joined_at' => now()]);
        }

        return $group;
    }

    /** The token on the end of a redirect to a held proposal. */
    protected function tokenFrom($response): string
    {
        return basename(parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }
}
