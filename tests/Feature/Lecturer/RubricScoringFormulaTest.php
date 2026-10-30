<?php

namespace Tests\Feature\Lecturer;

use App\Models\Rubric;
use App\Models\RubricSection;
use App\Models\Subject;
use App\Models\User;
use App\Support\Rubric\RubricParser;
use App\Support\Rubric\RubricScoring;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Each section chooses how a level becomes marks, because the weight column does
 * not mean the same in every table. Run against the sample, which mirrors the
 * real rubric: CLO2 is level x weight (maximum 30), CLO4 is level only (10).
 */
class RubricScoringFormulaTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE = __DIR__.'/../../../docs/samples/MarkingRubric.xlsx';

    protected User $lecturer;

    protected Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $this->subject = Subject::create(['lecturer_id' => $this->lecturer->id, 'code' => 'SECJ3104', 'name' => 'Application Development', 'group_size' => 4]);
    }

    protected function draft(): array
    {
        return (new RubricParser)->parse(self::SAMPLE, 'MarkingRubric.xlsx');
    }

    /** A one-table workbook with the given title, weights and a top level of 2. */
    protected function workbook(string $title, array $weights): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A1', $title);
        $sheet->fromArray(['Criteria', 'Weight', 'Poor (1)', 'Excellent (2)'], null, 'A2');

        foreach ($weights as $i => $weight) {
            $sheet->fromArray(["Criterion {$i}", $weight, 'low', 'high'], null, 'A'.(3 + $i));
        }

        $path = tempnam(sys_get_temp_dir(), 'rub').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'r.xlsx', null, null, true);
    }

    protected function review(UploadedFile $file): string
    {
        $url = $this->actingAs($this->lecturer)->post(route('lecturer.rubrics.store', $this->subject), ['file' => $file])
            ->assertRedirect()->headers->get('Location');

        return $url;
    }

    protected function payload(array $draft, ?callable $edit = null): array
    {
        $payload = ['name' => 'R', 'sections' => $draft['sections']];

        foreach ($payload['sections'] as &$section) {
            foreach ($section['criteria'] as &$criterion) {
                foreach ($criterion['levels'] as &$level) {
                    $level['range'] = $level['range'] ?? '';
                }
            }
        }
        unset($section, $criterion, $level);

        return $edit ? $edit($payload) : $payload;
    }

    /*
    | Reading the declared total and preselecting
    */

    public function test_clo2_preselects_level_times_weight_with_a_maximum_of_30(): void
    {
        $clo2 = $this->draft()['sections'][0];

        $this->assertSame('30', $clo2['declared_total']);
        $this->assertSame(RubricScoring::LEVEL_X_WEIGHT, $clo2['scoring']);
        $this->assertSame(30.0, RubricScoring::maxima($clo2['criteria'])[RubricScoring::LEVEL_X_WEIGHT]);
    }

    public function test_clo4_preselects_level_only_with_a_maximum_of_10(): void
    {
        $clo4 = $this->draft()['sections'][1];

        $this->assertSame('10', $clo4['declared_total']);
        $this->assertSame(RubricScoring::LEVEL_ONLY, $clo4['scoring']);
        $this->assertSame(10.0, RubricScoring::maxima($clo4['criteria'])[RubricScoring::LEVEL_ONLY]);
    }

    public function test_a_section_with_no_declared_total_requires_a_manual_choice(): void
    {
        $draft = (new RubricParser)->parse($this->workbook('Teamwork', [2, 2, 2])->getRealPath());

        $this->assertNull($draft['sections'][0]['declared_total']);
        $this->assertNull($draft['sections'][0]['scoring']);
        $this->assertStringContainsString('does not state a total', implode(' ', $draft['warnings']));
    }

    public function test_a_declared_total_that_matches_neither_formula_is_not_guessed(): void
    {
        $draft = (new RubricParser)->parse($this->workbook('Teamwork (25 marks)', [2, 2, 2])->getRealPath());

        $this->assertSame('25', $draft['sections'][0]['declared_total']);
        $this->assertNull($draft['sections'][0]['scoring']);
        $this->assertStringContainsString('Neither or both formulas give the declared total of 25', implode(' ', $draft['warnings']));
    }

    public function test_a_declared_total_that_matches_both_formulas_is_not_guessed(): void
    {
        // Weight 1, top level 2: both formulas give 2 per criterion, so 3 criteria = 6 either way.
        $draft = (new RubricParser)->parse($this->workbook('Teamwork (6 marks)', [1, 1, 1])->getRealPath());

        $this->assertSame('6', $draft['sections'][0]['declared_total']);
        $this->assertNull($draft['sections'][0]['scoring']);
    }

    public static function titles(): array
    {
        return [
            'parenthesised' => ['CLO2 – Report Quality & Presentation (30 marks)', '30'],
            'bare' => ['Teamwork 10 marks', '10'],
            'decimal' => ['Teamwork (12.5 marks)', '12.5'],
            'singular' => ['Quiz (1 mark)', '1'],
            'comma decimal' => ['Teamwork (12,5 marks)', '12.5'],
            'malay' => ['Kerja berpasukan (10 markah)', '10'],
            'last figure wins' => ['CLO4 (2024) – Teamwork (10 marks)', '10'],
            'none' => ['Teamwork', null],
            'a year is not a total' => ['Teamwork 2026', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('titles')]
    public function test_the_declared_total_is_read_from_the_title(string $title, ?string $expected): void
    {
        $draft = (new RubricParser)->parse($this->workbook($title, [2, 2])->getRealPath());

        $this->assertSame($expected, $draft['sections'][0]['declared_total']);
    }

    /*
    | The review screen
    */

    public function test_the_review_shows_the_declared_total_and_both_maxima_for_each_section(): void
    {
        $html = $this->actingAs($this->lecturer)->get($this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true)))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Declared total', $html);
        $this->assertSame(2, substr_count($html, 'Maximum with level × weight'));
        $this->assertSame(2, substr_count($html, 'Maximum with level only'));
        $this->assertSame(2, substr_count($html, 'Maximum with the chosen formula'));

        // CLO2 shows 30 under level x weight, 35 under level only; CLO4 shows 20 and 10.
        $this->assertMatchesRegularExpression('/with level × weight<\/dt>\s*<dd[^>]*>30</', $html);
        $this->assertMatchesRegularExpression('/with level only<\/dt>\s*<dd[^>]*>35</', $html);
        $this->assertMatchesRegularExpression('/with level × weight<\/dt>\s*<dd[^>]*>20</', $html);
        $this->assertMatchesRegularExpression('/with level only<\/dt>\s*<dd[^>]*>10</', $html);

        // Preselected, and the status line says they match.
        $this->assertSame(2, substr_count($html, 'matches the declared total'));
        preg_match_all('/<option value="(level_x_weight|level_only)"\s+selected/', $html, $selected);
        $this->assertSame(['level_x_weight', 'level_only'], $selected[1]);
    }

    public function test_the_review_warns_where_the_maximum_differs_from_the_declared_total(): void
    {
        $url = $this->review($this->workbook('Teamwork (25 marks)', [2, 2, 2]));

        $this->actingAs($this->lecturer)->get($url)->assertOk()
            ->assertSee('Neither or both formulas give the declared total of 25')
            ->assertSee('Choose…')
            ->assertSee('cannot be saved until this is chosen');
    }

    public function test_a_chosen_formula_that_differs_from_the_total_is_flagged_on_screen(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->from($url)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][0]['scoring'] = 'level_only';   // 35, against a declared 30
            $p['sections'][1]['criteria'][0]['name'] = '';  // force the form back

            return $p;
        }))->assertRedirect($url);

        $this->actingAs($this->lecturer)->get($url)
            ->assertSee('The maximum is 35 but the declared total is 30.');
    }

    /*
    | Blocking an unresolved system-suggested section
    */

    public function test_a_system_suggested_section_cannot_be_saved_without_a_formula(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][1]['scoring'] = '';

            return $p;
        }))->assertSessionHasErrors(['sections.1.scoring' => 'Choose how marks are calculated for “CLO4 – Teamwork (10 marks)” before saving.']);

        $this->assertSame(0, Rubric::count());
    }

    public function test_one_system_suggested_criterion_in_a_manual_section_also_needs_a_formula(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][0]['scoring'] = '';
            $p['sections'][0]['criteria'][2]['mode'] = 'suggested';

            return $p;
        }))->assertSessionHasErrors('sections.0.scoring');
    }

    public function test_a_manual_section_may_be_saved_with_the_formula_unchosen(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][0]['scoring'] = '';

            return $p;
        }))->assertSessionHasNoErrors();

        $this->assertNull(RubricSection::where('title', 'like', 'CLO2%')->firstOrFail()->scoring);
    }

    public function test_level_times_weight_needs_a_weight_on_every_criterion(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][0]['criteria'][3]['weight'] = '';

            return $p;
        }))->assertSessionHasErrors('sections.0.scoring');
    }

    public function test_an_unknown_formula_is_refused(): void
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));

        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft(), function ($p) {
            $p['sections'][1]['scoring'] = 'average';

            return $p;
        }))->assertSessionHasErrors('sections.1.scoring');
    }

    /*
    | Stored, and used in the arithmetic
    */

    protected function saved(): Rubric
    {
        $url = $this->review(new UploadedFile(self::SAMPLE, 'MarkingRubric.xlsx', null, null, true));
        $this->actingAs($this->lecturer)->post($url, $this->payload($this->draft()))->assertSessionHasNoErrors();

        return Rubric::with('sections.criteria.levels')->firstOrFail();
    }

    public function test_the_formula_and_declared_total_are_stored_on_the_section(): void
    {
        [$clo2, $clo4] = $this->saved()->sections;

        $this->assertSame('level_x_weight', $clo2->scoring);
        $this->assertSame('30.00', $clo2->declared_total);
        $this->assertSame(30.0, $clo2->maximumMarks());

        $this->assertSame('level_only', $clo4->scoring);
        $this->assertSame('10.00', $clo4->declared_total);
        $this->assertSame(10.0, $clo4->maximumMarks());
    }

    public function test_a_clo4_student_at_excellent_on_every_criterion_totals_10_not_20(): void
    {
        $clo4 = $this->saved()->sections[1];

        $total = 0.0;
        $ifMultiplied = 0.0;

        foreach ($clo4->criteria as $criterion) {
            $excellent = $criterion->levels->firstWhere('label', 'Excellent');

            $total += $clo4->marksFor($criterion, $excellent);
            $ifMultiplied += RubricScoring::marks(RubricScoring::LEVEL_X_WEIGHT, $criterion->weight, $excellent->marks);
        }

        $this->assertEqualsWithDelta(10.0, $total, 0.001);
        $this->assertEqualsWithDelta(20.0, $ifMultiplied, 0.001, 'What a fixed level × weight formula would have said.');
    }

    public function test_a_clo2_student_at_excellent_on_every_criterion_totals_30(): void
    {
        $clo2 = $this->saved()->sections[0];

        $total = $clo2->criteria->sum(fn ($c) => $clo2->marksFor($c, $c->levels->firstWhere('label', 'Excellent')));

        $this->assertEqualsWithDelta(30.0, $total, 0.001);
    }

    public function test_a_section_with_no_formula_refuses_to_compute_marks(): void
    {
        $section = $this->saved()->sections[0];
        $section->update(['scoring' => null]);
        $criterion = $section->criteria[0];

        $this->expectException(\LogicException::class);

        $section->fresh()->marksFor($criterion, $criterion->levels[0]);
    }

    public function test_every_total_is_explained(): void
    {
        [$clo2, $clo4] = $this->saved()->sections;

        $this->assertSame('marks = level marks × weight; maximum 30; declared total 30', $clo2->scoringExplained());
        $this->assertSame('marks = level marks (the weight is the maximum); maximum 10; declared total 10', $clo4->scoringExplained());
    }
}
