<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting back out of a lecturer detail screen.
 *
 * The shell has supported a back link above the heading since it was written,
 * and says why in its own comment: the header actions sit on the far right,
 * which is too easy to miss when you are deep in a screen. Three detail
 * screens had ignored it and put a secondary pill in the corner instead, so
 * "All SECJ3104 groups" read as a stray filter rather than the way out.
 */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $lecturer;

    protected Subject $subject;

    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);

        $this->subject = Subject::create([
            'lecturer_id' => $this->lecturer->id,
            'code' => 'SECJ3104',
            'name' => 'App Dev',
            'group_size' => 4,
        ]);

        $this->group = Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Group A', 'group_size' => 4,
        ]);
    }

    protected function secondGroup(): Group
    {
        return Group::create([
            'subject_id' => $this->subject->id, 'name' => 'Group B', 'group_size' => 4,
        ]);
    }

    /**
     * The back link is the arrow link above the heading, not a corner pill and
     * not the sidebar.
     *
     * Matched on the shell's own back-link class, because every one of these
     * destinations is also a sidebar entry carrying an icon — an assertion on
     * the href alone passes on the sidebar and proves nothing.
     */
    protected function backLinkPattern(string $url): string
    {
        return '/<a href="'.preg_quote($url, '/').'"\s+class="mb-1\.5 inline-flex/';
    }

    protected function assertBacksTo(string $url, string $html): void
    {
        $this->assertMatchesRegularExpression(
            $this->backLinkPattern($url), $html, "No back link pointing at {$url}."
        );
    }

    public function test_managing_a_group_offers_a_way_back_to_its_subject(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->assertSee('SECJ3104 groups')
            ->getContent();

        // Back to the list filtered to this subject: the shelf the group came
        // from, rather than every group the lecturer teaches.
        $this->assertBacksTo(route('lecturer.groups', ['subject' => $this->subject->id]), $html);
    }

    public function test_the_old_corner_button_is_gone_from_the_group_screen(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.groups.show', $this->group))
            ->assertOk()
            ->assertDontSee('All SECJ3104 groups');
    }

    public function test_a_contribution_report_offers_a_way_back(): void
    {
        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.reports.show', $this->group))
            ->assertOk()
            ->getContent();

        $this->assertBacksTo(route('lecturer.reports'), $html);
    }

    public function test_monitoring_offers_a_way_back_when_there_is_a_picker_to_return_to(): void
    {
        $this->secondGroup();

        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->assertOk()
            ->getContent();

        $this->assertBacksTo(route('lecturer.progress'), $html);
    }

    public function test_monitoring_hides_the_back_link_when_there_is_only_one_group(): void
    {
        // The picker redirects straight to this screen when a lecturer has one
        // group, so a back link would look like a way out and land you here
        // again. Better no link than a link that does nothing.
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress'))
            ->assertRedirect(route('lecturer.progress.show', $this->group));

        $html = $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            $this->backLinkPattern(route('lecturer.progress')), $html
        );

        // The sidebar entry is still there, which is why the assertion above
        // has to be specific about which link it is looking at.
        $this->assertStringContainsString(route('lecturer.progress'), $html);
    }

    public function test_the_forward_actions_on_monitoring_are_untouched(): void
    {
        $this->actingAs($this->lecturer)
            ->get(route('lecturer.progress.show', $this->group))
            ->assertOk()
            ->assertSee('Contribution report')
            ->assertSee('Manage members');
    }
}
