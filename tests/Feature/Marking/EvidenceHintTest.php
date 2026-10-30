<?php

namespace Tests\Feature\Marking;

use App\Support\Marking\EvidenceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsMarkingFixture;
use Tests\TestCase;

/**
 * The evidence-type control's advice: what each option measures, the note about
 * Manual, and the amber hint when the choice does not seem to fit the wording.
 * The hint is advice only; every choice stays open.
 */
class EvidenceHintTest extends TestCase
{
    use BuildsMarkingFixture;
    use RefreshDatabase;

    private const ATTITUDE = 'This looks like a judgement about attitude or quality, which recorded activity cannot measure. Consider setting the criterion to Manual.';

    private const RELIABILITY = 'The wording mentions deadlines or reliability. Reliability (deadlines) is probably the better evidence type.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMarkingFixture();
    }

    /*
    | The rules
    */

    public static function cases(): array
    {
        $attitude = self::ATTITUDE;
        $reliability = self::RELIABILITY;

        return [
            // Attitude and quality belong with the lecturer.
            'respect, set to communication' => ['Respect for teammates', 'suggested', 'communication', $attitude],
            'support, set to collaboration' => ['Supports other members', 'suggested', 'collaboration', $attitude],
            'ideas, set to task completion' => ['Value of ideas contributed', 'suggested', 'task_completion', $attitude],
            'quality, set to collaboration' => ['Quality of contributions', 'suggested', 'collaboration', $attitude],
            'uppercase wording' => ['RESPECT FOR OTHERS', 'suggested', 'communication', $attitude],
            'attitude, set to manual' => ['Respect for teammates', 'manual', 'communication', null],
            'attitude, evidence none' => ['Respect for teammates', 'suggested', 'none', null],
            'attitude, evidence unset' => ['Respect for teammates', 'suggested', '', null],

            // Deadlines and reliability belong to Reliability.
            'deadlines, set to task completion' => ['Meets deadlines', 'suggested', 'task_completion', $reliability],
            'reliab, set to communication' => ['Reliability in the team', 'suggested', 'communication', $reliability],
            'deadlines, set to none' => ['Meets deadlines', 'suggested', 'none', $reliability],
            'deadlines, left unset' => ['Meets deadlines', 'suggested', null, $reliability],
            'deadlines, set to reliability' => ['Reliability and deadlines', 'suggested', 'reliability', null],
            'deadlines, set to manual' => ['Meets deadlines', 'manual', 'task_completion', null],
            'on time wording' => ['Submits work on time', 'suggested', 'collaboration', $reliability],

            // Attitude wording wins over a deadline word.
            'respect for deadlines' => ['Respect for deadlines', 'suggested', 'reliability', $attitude],

            // Anything else the wording points to.
            'communication wording, set to reliability' => ['Communication', 'suggested', 'reliability', 'The wording looks more like Communication. Check this is the evidence type you want.'],
            'task wording, set to communication' => ['Contribution to tasks', 'suggested', 'communication', 'The wording looks more like Task completion. Check this is the evidence type you want.'],
            'collaboration wording, set to task completion' => ['Collaboration', 'suggested', 'task_completion', 'The wording looks more like Collaboration. Check this is the evidence type you want.'],

            // And nothing when it fits, or there is nothing to go on.
            'task wording, set to task completion' => ['Contribution to tasks', 'suggested', 'task_completion', null],
            'communication, set to communication' => ['Communication', 'suggested', 'communication', null],
            'no clear wording, any type' => ['Initiative and problem solving', 'suggested', 'communication', null],
            'no clear wording, unset' => ['Initiative and problem solving', 'suggested', '', null],
            'a mismatch is not raised for none' => ['Contribution to tasks', 'suggested', 'none', null],
            'empty wording' => ['', 'suggested', 'communication', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_the_hint_for_a_criterion(string $name, string $mode, ?string $evidence, ?string $expected): void
    {
        $this->assertSame($expected, EvidenceType::hint($name, $mode, $evidence));
    }

    public function test_every_hint_is_neutral_advice_not_a_block(): void
    {
        foreach (EvidenceType::messages() as $message) {
            $this->assertStringNotContainsStringIgnoringCase('peer', $message);
            $this->assertStringNotContainsStringIgnoringCase('must', $message);
            $this->assertStringNotContainsStringIgnoringCase('invalid', $message);
            $this->assertStringNotContainsStringIgnoringCase('error', $message);
        }
    }

    public function test_the_browser_applies_exactly_the_same_rules(): void
    {
        if (! is_string(@shell_exec('node --version 2>&1')) || ! str_starts_with(trim((string) shell_exec('node --version 2>&1')), 'v')) {
            $this->markTestSkipped('Node is not available to run the browser rules.');
        }

        $cases = array_map(fn ($case) => array_slice($case, 0, 3), self::cases());
        $cases = array_values($cases);

        $script = tempnam(sys_get_temp_dir(), 'hint').'.mjs';
        $module = 'file:///'.str_replace('\\', '/', realpath(resource_path('js/evidence-picker.js')));

        file_put_contents($script, "import picker, { hint, guess } from '{$module}';\n"
            .'const rules = '.json_encode(EvidenceType::hintConfig()).";\n"
            .'const cases = '.json_encode($cases).";\n"
            ."console.log(JSON.stringify(cases.map(([name, mode, ev]) => [hint(name, mode, ev ?? '', rules), guess(name, rules)])));\n");

        $output = shell_exec('node '.escapeshellarg($script).' 2>&1');
        @unlink($script);

        $actual = json_decode(trim((string) $output), true);
        $this->assertIsArray($actual, 'The script did not run: '.$output);

        foreach ($cases as $i => [$name, $mode, $evidence]) {
            $this->assertSame(EvidenceType::hint($name, $mode, $evidence) ?? '', $actual[$i][0], "hint for “{$name}” as {$mode}/".($evidence ?? 'null'));
            $this->assertSame(EvidenceType::guess($name), $actual[$i][1], "guess for “{$name}”");
        }
    }

    /*
    | Descriptions and the note
    */

    public function test_each_type_says_exactly_what_it_measures(): void
    {
        $this->assertSame('Share of assigned tasks that are completed', EvidenceType::description('task_completion'));
        $this->assertSame('Messages sent compared with the group average', EvidenceType::description('communication'));
        $this->assertSame('Document edits and file uploads compared with the group average', EvidenceType::description('collaboration'));
        $this->assertStringContainsString('completed on time', EvidenceType::description('reliability'));
        $this->assertStringContainsString('you choose the level yourself', EvidenceType::description('none'));
        $this->assertSame(EvidenceType::description('none'), EvidenceType::description(null), 'Unset reads as none.');

        foreach (EvidenceType::ALL as $type) {
            $this->assertStringNotContainsString("\n", EvidenceType::description($type), 'One line.');
        }
    }

    protected function reviewHtml(): string
    {
        $url = $this->actingAs($this->lecturer)->post(
            route('lecturer.rubrics.store', $this->subject),
            ['file' => new UploadedFile(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx', 'MarkingRubric.xlsx', null, null, true)],
        )->assertRedirect()->headers->get('Location');

        return $this->actingAs($this->lecturer)->get($url)->assertOk()->getContent();
    }

    /** The description line under each control, with the value its select has. */
    protected function descriptionLines(string $html): array
    {
        preg_match_all('/<select id="evidence[^"]*" name="[^"]*"[^>]*>(.*?)<\/select>.*?data-evidence-description[^>]*>(.*?)<\/p>/s', $html, $m, PREG_SET_ORDER);

        return array_map(function ($row) {
            preg_match('/<option value="([^"]*)"\s+selected/', $row[1], $selected);

            return ['selected' => $selected[1] ?? null, 'text' => html_entity_decode(trim($row[2]))];
        }, $m);
    }

    public function test_the_description_line_matches_the_selected_option(): void
    {
        foreach ([null, 'task_completion', 'reliability', 'communication', 'collaboration', 'none'] as $selected) {
            $html = \Illuminate\Support\Facades\Blade::render(
                '<x-evidence-picker name="evidence[1]" :selected="$selected" criterion-name="Anything" standalone />',
                ['selected' => $selected],
            );

            $lines = $this->descriptionLines($html);

            $this->assertCount(1, $lines, 'One description line, however many options there are.');
            $this->assertSame(EvidenceType::description($selected), $lines[0]['text'], (string) ($selected ?? 'unset'));
            $this->assertSame($selected ?? '', $lines[0]['selected'] ?? '');
        }
    }

    public function test_the_browser_has_the_same_description_for_every_value_of_the_select(): void
    {
        $config = EvidenceType::hintConfig();

        foreach (EvidenceType::ALL as $type) {
            $this->assertSame(EvidenceType::description($type), $config['descriptions'][$type], $type);
        }

        $this->assertSame(EvidenceType::description(null), $config['descriptions'][''], 'Not set reads as none.');
        $this->assertSame(6, count($config['descriptions']));
    }

    public function test_the_review_is_compact_one_select_and_one_description_per_criterion_and_one_note_per_section(): void
    {
        $html = $this->reviewHtml();

        $this->assertSame(12, preg_match_all('/<select id="evidence-\d+-\d+"/', $html), 'One select per criterion.');
        $this->assertSame(12, substr_count($html, 'data-evidence-description'), 'One description line per criterion, not one per option.');
        $this->assertSame(0, substr_count($html, 'type="radio"'), 'No radio list.');

        // Each option's text is the option's label only: the description is shown for the chosen one.
        $this->assertSame(0, preg_match('/<option[^>]*>[^<]*compared with the group average/', $html));

        // The note is said once per section (two sections), not on every criterion.
        $this->assertSame(2, substr_count($html, 'Use Manual for criteria about attitude or quality, such as respect or the value of ideas. Recorded activity cannot measure these.'));
        $this->assertSame(2, substr_count($html, 'data-evidence-note'));

        // And the note sits before the section's criteria, not inside one.
        $this->assertMatchesRegularExpression('/data-evidence-note.*?data-criterion/s', $html);
    }

    public function test_the_saved_rubric_is_compact_too(): void
    {
        $html = $this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->assertOk()->getContent();

        $this->assertSame(5, preg_match_all('/<select id="evidence-\d+"/', $html), 'Only the five System-suggested criteria have a control.');
        $this->assertSame(5, substr_count($html, 'data-evidence-description'));
        $this->assertSame(1, substr_count($html, 'data-evidence-note'), 'Once, for the one section that has suggested criteria.');
        $this->assertSame(0, substr_count($html, 'type="radio"'));

        // The control belongs to the criterion's own cell, and the levels start at the top of their cell.
        $this->assertStringContainsString('<td class="align-top">', $html);
    }

    public function test_a_default_note_shows_only_while_the_value_is_the_untouched_default(): void
    {
        $shown = function (?string $selected): bool {
            $html = \Illuminate\Support\Facades\Blade::render(
                '<x-evidence-picker name="e" :selected="$s" criterion-name="Communication" standalone />',
                ['s' => $selected],
            );

            preg_match('/<span class="([^"]*)"\s+:class="\{ hidden: ! isDefault[^"]*"\s+data-evidence-default/', $html, $m);

            return isset($m[1]) && ! preg_match('/(^|\s)hidden(\s|$)/', $m[1]);
        };

        $this->assertTrue($shown('communication'), 'The wording suggests Communication and it is still chosen.');
        $this->assertFalse($shown('reliability'), 'Changed, so it is no longer the default.');
        $this->assertFalse($shown(null), 'Unset is not a default.');
    }
    /*
    | The hint on the page
    */

    /** The hint paragraphs' class lists, in page order, and their text. */
    protected function hints(string $html): array
    {
        preg_match_all('/<p data-evidence-hint role="status"\s+class="([^"]*)".*?<span x-text="[^"]*">(.*?)<\/span>/s', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($row) => [
            'visible' => ! preg_match('/(^|\s)hidden(\s|$)/', $row[1]),
            'text' => html_entity_decode(trim($row[2])),
        ], $m);
    }

    public function test_the_saved_rubric_shows_the_hint_for_a_mismatched_criterion_and_only_then(): void
    {
        $rubric = $this->saveSampleRubric(function ($data) {
            $criteria = &$data['sections'][1]['criteria'];

            $criteria[0]['name'] = 'Respect for ideas';          $criteria[0]['evidence_type'] = 'communication';   // attitude
            $criteria[1]['name'] = 'Meets deadlines';            $criteria[1]['evidence_type'] = 'task_completion'; // reliability wording
            $criteria[2]['name'] = 'Communication';              $criteria[2]['evidence_type'] = 'reliability';     // looks like communication
            $criteria[3]['name'] = 'Collaboration';              $criteria[3]['evidence_type'] = 'collaboration';   // fits
            $criteria[4]['name'] = 'Support for the team';       $criteria[4]['evidence_type'] = 'none';            // attitude, but none

            return $data;
        });

        $hints = $this->hints($this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $rubric]))->getContent());

        $this->assertCount(5, $hints);

        $this->assertTrue($hints[0]['visible']);
        $this->assertSame(self::ATTITUDE, $hints[0]['text']);

        $this->assertTrue($hints[1]['visible']);
        $this->assertSame(self::RELIABILITY, $hints[1]['text']);

        $this->assertTrue($hints[2]['visible']);
        $this->assertSame('The wording looks more like Communication. Check this is the evidence type you want.', $hints[2]['text']);

        $this->assertFalse($hints[3]['visible'], 'A criterion that fits has no hint.');
        $this->assertFalse($hints[4]['visible'], 'None is already lecturer-only, so nothing to advise.');
    }

    public function test_the_sample_rubric_raises_no_hint_at_all(): void
    {
        $hints = $this->hints($this->actingAs($this->lecturer)->get(route('lecturer.rubrics.show', [$this->subject, $this->rubric]))->getContent());

        $this->assertCount(5, $hints);
        $this->assertSame([], array_values(array_filter($hints, fn ($h) => $h['visible'])));
    }

    public function test_a_hint_never_stops_the_choice_from_being_saved(): void
    {
        $criterion = $this->criterion('Collaboration');

        $this->actingAs($this->lecturer)->put(route('lecturer.rubrics.evidence', [$this->subject, $this->rubric]), [
            'evidence' => [$criterion->id => 'task_completion'],   // the wording says collaboration
        ])->assertSessionHasNoErrors();

        $this->assertSame('task_completion', $criterion->fresh()->evidence_type);
    }

    public function test_the_review_shows_the_hint_on_first_paint_and_wires_it_to_update_live(): void
    {
        $html = $this->reviewHtml();

        // Live: bound to the criterion's own name, mode and evidence, and given the rules.
        $this->assertStringContainsString('x-text="hintFor(name, cmode, ev)"', $html);
        $this->assertStringContainsString('x-model="name"', $html);
        $this->assertStringContainsString('x-model="ev"', $html);
        $this->assertStringContainsString('evidencePicker(', $html);
        $this->assertStringContainsString('respect', $html);   // the rules are in the page for the browser to apply

        // The defaults chosen from the wording fit it, so nothing is flagged on arrival.
        $this->assertSame([], array_values(array_filter($this->hints($html), fn ($h) => $h['visible'])));
    }

    public function test_the_review_flags_a_mismatch_on_return_to_the_form(): void
    {
        $url = $this->actingAs($this->lecturer)->post(
            route('lecturer.rubrics.store', $this->subject),
            ['file' => new UploadedFile(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx', 'MarkingRubric.xlsx', null, null, true)],
        )->headers->get('Location');

        $draft = (new \App\Support\Rubric\RubricParser)->parse(__DIR__.'/../../../docs/samples/MarkingRubric.xlsx');
        $payload = ['name' => '', 'sections' => $draft['sections']];   // refused, so the form comes back
        $payload['sections'][1]['criteria'][0]['evidence_type'] = 'communication';   // "Contribution to tasks"

        foreach ($payload['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                foreach ($criterion['levels'] as &$level) {
                    $level['range'] = $level['range'] ?? '';
                }
            }
        }
        unset($section, $criterion, $level);

        $this->actingAs($this->lecturer)->from($url)->post($url, $payload)->assertRedirect($url);

        $visible = array_values(array_filter($this->hints($this->actingAs($this->lecturer)->get($url)->getContent()), fn ($h) => $h['visible']));

        $this->assertCount(1, $visible);
        $this->assertSame('The wording looks more like Task completion. Check this is the evidence type you want.', $visible[0]['text']);
    }

    public function test_no_default_is_offered_for_attitude_wording(): void
    {
        $this->assertNull(EvidenceType::guess('Helps and supports others'));
        $this->assertNull(EvidenceType::guess('Respect for teammates'));
        $this->assertSame('collaboration', EvidenceType::guess('Helps others with their work'));
    }
}
