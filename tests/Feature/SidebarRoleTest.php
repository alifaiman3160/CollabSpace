<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar must show the signed-in user's real role.
 *
 * This broke because views passed role="lecturer" to <x-layouts.app>. Blade
 * unsets any variable whose name collides with a component attribute, so that
 * attribute deleted the $role the view composer had set, and the fallback in
 * the template rendered "student" for everybody — while the nav itself, built
 * from the composer's own value, stayed correct. The mismatch is what makes it
 * easy to miss.
 */
class SidebarRoleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: User} */
    protected function cohort(): array
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $student = User::factory()->student()->create(['must_change_password' => false]);

        $subject = Subject::create([
            'lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4,
        ]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Group A', 'group_size' => 4]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        return [$admin, $lecturer, $student];
    }

    public function test_the_sidebar_names_the_right_portal_for_each_role(): void
    {
        [$admin, $lecturer, $student] = $this->cohort();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Administrator Portal');

        $this->actingAs($lecturer)->get(route('lecturer.dashboard'))
            ->assertOk()->assertSee('Lecturer Portal');

        $this->actingAs($student)->get(route('student.dashboard'))
            ->assertOk()->assertSee('Student Portal');
    }

    /**
     * The regression itself: a lecturer must never be labelled a student, on
     * any screen — including the ones that used to pass role= explicitly.
     */
    public function test_a_lecturer_is_never_labelled_a_student(): void
    {
        [, $lecturer] = $this->cohort();

        $pages = [
            route('lecturer.dashboard'),
            route('lecturer.subjects'),
            route('lecturer.groups'),
            route('lecturer.reports'),
            route('lecturer.students'),
            route('lecturer.progress'),
            route('lecturer.notifications'),
            route('account'),
        ];

        // followingRedirects because lecturer.progress goes straight through to
        // the single group when there is only one, which is correct behaviour.
        foreach ($pages as $url) {
            $this->actingAs($lecturer)->followingRedirects()->get($url)
                ->assertOk()
                ->assertSee('Lecturer Portal')
                ->assertDontSee('Student Portal')
                ->assertDontSee('Administrator Portal');
        }
    }

    public function test_an_admin_is_never_labelled_a_student(): void
    {
        [$admin] = $this->cohort();

        foreach ([route('admin.dashboard'), route('admin.users'), route('admin.notifications'), route('account')] as $url) {
            $this->actingAs($admin)->followingRedirects()->get($url)
                ->assertOk()
                ->assertSee('Administrator Portal')
                ->assertDontSee('Student Portal');
        }
    }

    public function test_the_user_block_shows_the_signed_in_person(): void
    {
        [, $lecturer] = $this->cohort();

        $this->actingAs($lecturer)->get(route('lecturer.dashboard'))
            ->assertOk()
            ->assertSee($lecturer->name)
            ->assertSee($lecturer->email);
    }

    /**
     * No view may pass role= to the layout again — it is the attribute that
     * caused the bug, and the role is a property of the user, not the page.
     */
    public function test_no_view_passes_a_role_attribute_to_the_layout(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/\brole="(admin|lecturer|student)"/', file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders,
            'These views pass role= to the layout, which unsets $role inside it: '.implode(', ', $offenders));
    }
}
