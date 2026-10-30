<?php

namespace Tests\Feature\Lecturer;

use App\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFormationCohort;
use Tests\TestCase;

/**
 * The formation screens as a whole.
 *
 * Two kinds of check that the flow tests do not make. That no screen leaks raw
 * template syntax: Blade is unforgiving about directives that happen to appear
 * in prose, and a leak shows up as "@if" printed in the page rather than as a
 * failure. And that the review screen lists every category of problem the brief
 * asked for, in one realistic file at once.
 */
class FormationScreensTest extends TestCase
{
    use BuildsFormationCohort;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildCohort();
    }

    protected function m(int $n): string
    {
        return sprintf('A21EC%04d', $n);
    }

    /** @param  array<string, string>  $pages */
    protected function assertNoTemplateLeaks(array $pages): void
    {
        $leaks = ['@if', '@endif', '@foreach', '@endforeach', '@php', '@endphp', '@else', '@csrf', '@checked', '@selected', '{{', '}}'];

        foreach ($pages as $label => $html) {
            $text = $this->visibleText($html);

            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $text, "{$label} shows a raw “{$leak}”.");
            }
        }
    }

    /** The text a reader sees: no tags, no scripts, no attribute values. */
    protected function visibleText(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html);

        return html_entity_decode(strip_tags($html));
    }

    public function test_no_formation_screen_leaks_template_syntax(): void
    {
        $pages = [];

        $pages['hub'] = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', $this->subject))->assertOk()->getContent();

        $pages['hub after import'] = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation', [$this->subject, 'after' => 'import']))->assertOk()->getContent();

        $preview = $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.balanced.preview', $this->subject),
            ['students' => $this->cohort->pluck('id')->all(), 'groups' => 4],
        );

        $pages['balanced preview'] = $this->actingAs($this->lecturer)
            ->get($preview->headers->get('Location'))->assertOk()->getContent();

        $pages['import upload'] = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import', $this->subject))->assertOk()->getContent();

        $csv = "Timestamp,Group name,Member 1,Member 2,Member 3,Member 4,Member 5\n"
            .'x,Alpha,'.$this->m(1).','.$this->m(2).",,,\n"
            .'x,Beta,'.$this->m(1).',A99XX9999,Ahmad Faiz,,'."\n"
            .'x,Gamma,'.$this->m(3).','.$this->m(4).','.$this->m(5).','.$this->m(6).','.$this->m(7)."\n";

        $store = $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'layout' => 'group_rows',
            'file' => UploadedFile::fake()->createWithContent('r.csv', $csv),
        ]);

        $columns = $store->headers->get('Location');
        $token = basename(dirname(parse_url($columns, PHP_URL_PATH)));

        $pages['import columns'] = $this->actingAs($this->lecturer)->get($columns)->assertOk()->getContent();

        $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.import.map', [$this->subject, $token]),
            ['roles' => ['ignore', 'group', 'member', 'member', 'member', 'member', 'member']],
        );

        $pages['import review'] = $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.review', [$this->subject, $token]))->assertOk()->getContent();

        $this->assertNoTemplateLeaks($pages);
    }

    public function test_the_review_screen_lists_every_category_at_once(): void
    {
        // One file with a clean group, a student not in the system, a student in
        // two groups, a group over the limit, and students who never appear.
        $csv = "Timestamp,Group name,Member 1,Member 2,Member 3,Member 4,Member 5\n"
            .'x,Alpha,'.$this->m(1).','.$this->m(2).",,,\n"
            .'x,Beta,'.$this->m(3).',A99XX9999,,,'."\n"
            .'x,Gamma,'.$this->m(1).','.$this->m(4).",,,\n"
            .'x,Delta,'.$this->m(5).','.$this->m(6).','.$this->m(7).','.$this->m(8).','.$this->m(9)."\n";

        $store = $this->actingAs($this->lecturer)->post(route('lecturer.formation.import.store', $this->subject), [
            'layout' => 'group_rows',
            'file' => UploadedFile::fake()->createWithContent('r.csv', $csv),
        ]);

        $token = basename(dirname(parse_url($store->headers->get('Location'), PHP_URL_PATH)));

        $this->actingAs($this->lecturer)->post(
            route('lecturer.formation.import.map', [$this->subject, $token]),
            ['roles' => ['ignore', 'group', 'member', 'member', 'member', 'member', 'member']],
        );

        $this->actingAs($this->lecturer)
            ->get(route('lecturer.formation.import.review', [$this->subject, $token]))
            ->assertOk()
            // Groups that parsed cleanly.
            ->assertSee('Groups that read cleanly')
            // Students named but not found in the system.
            ->assertSee('Students not found')
            ->assertSee('A99XX9999')
            // Students appearing in more than one group.
            ->assertSee('Students in more than one group')
            ->assertSee('Listed in Alpha and Gamma')
            // Groups over the subject's size limit.
            ->assertSee('Groups over the size limit')
            ->assertSee('5 members, limit 4')
            // Students who never appear at all.
            ->assertSee('Students who never appear')
            ->assertSee($this->m(13));

        $this->assertSame(0, Group::count());
    }
}
