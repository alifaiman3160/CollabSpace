<?php

namespace Tests\Unit;

use App\Support\Rubric\RubricException;
use App\Support\Rubric\RubricParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class RubricParserTest extends TestCase
{
    private const SAMPLE = __DIR__.'/../../docs/samples/MarkingRubric.xlsx';

    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    protected function parser(): RubricParser
    {
        return new RubricParser;
    }

    /** @param  list<list<mixed>>  $rows  one array per spreadsheet row, null for a blank cell */
    protected function workbook(array $rows, string $title = 'Sheet1'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($title);

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) || is_float($value) ? 'n' : 's');
                }
            }
        }

        return $this->save($book);
    }

    protected function save(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rub').'.xlsx';
        (new Xlsx($book))->save($path);

        return $this->temp[] = $path;
    }

    /*
    | The sample file
    */

    public function test_both_tables_in_the_sample_are_found(): void
    {
        $draft = $this->parser()->parse(self::SAMPLE, 'MarkingRubric.xlsx');

        $this->assertCount(2, $draft['sections']);
        $this->assertSame('CLO2 – Report Quality & Presentation (30 marks)', $draft['sections'][0]['title']);
        $this->assertSame('CLO4 – Teamwork (10 marks)', $draft['sections'][1]['title']);
        $this->assertCount(7, $draft['sections'][0]['criteria']);
        $this->assertCount(5, $draft['sections'][1]['criteria']);
        $this->assertSame('Marking Rubric', $draft['name']);
        $this->assertSame('Rubric', $draft['sheet']);
        $this->assertSame([], $draft['warnings']);
    }

    public function test_weights_and_criteria_names_are_read(): void
    {
        $draft = $this->parser()->parse(self::SAMPLE);

        $clo2 = $draft['sections'][0]['criteria'];
        $clo4 = $draft['sections'][1]['criteria'];

        $this->assertSame(['0.4', '1', '1', '1.6', '0.8', '0.6', '0.6'], array_column($clo2, 'weight'));
        $this->assertSame('Abstract & introduction', $clo2[0]['name']);
        $this->assertSame('Conclusion & references', $clo2[6]['name']);
        $this->assertSame(['2', '2', '2', '2', '2'], array_column($clo4, 'weight'));
        $this->assertSame('Contribution to tasks', $clo4[0]['name']);
        $this->assertSame('Collaboration', $clo4[3]['name']);
    }

    public function test_the_range_level_keeps_its_upper_bound_and_shows_the_range(): void
    {
        $levels = $this->parser()->parse(self::SAMPLE)['sections'][0]['criteria'][0]['levels'];

        $this->assertSame(['Poor', 'Fair', 'Good', 'Excellent'], array_column($levels, 'label'));
        $this->assertSame(['1', '2', '3', '5'], array_column($levels, 'marks'));
        $this->assertNull($levels[0]['range']);
        $this->assertSame('4–5', $levels[3]['range']);
        $this->assertSame('Missing or unclear.', $levels[0]['descriptor']);
    }

    public function test_decimal_levels_such_as_good_1_5_and_excellent_2_0_are_read(): void
    {
        $levels = $this->parser()->parse(self::SAMPLE)['sections'][1]['criteria'][0]['levels'];

        $this->assertSame(['Poor', 'Fair', 'Good', 'Excellent'], array_column($levels, 'label'));
        $this->assertSame(['0.5', '1', '1.5', '2'], array_column($levels, 'marks'));
        $this->assertSame([null, null, null, null], array_column($levels, 'range'));
    }

    public function test_sections_default_by_what_they_are_about(): void
    {
        $sections = $this->parser()->parse(self::SAMPLE)['sections'];

        // Report quality is the lecturer's alone; teamwork may be suggested from activity.
        $this->assertSame('manual', $sections[0]['mode']);
        $this->assertSame('suggested', $sections[1]['mode']);

        foreach ($sections as $section) {
            foreach ($section['criteria'] as $criterion) {
                $this->assertSame($section['mode'], $criterion['mode']);
            }
        }
    }

    /*
    | Level headers
    */

    public static function levelHeaders(): array
    {
        return [
            'whole number' => ['Poor (1)', 'Poor', '1', null],
            'en dash range' => ['Excellent (4–5)', 'Excellent', '5', '4–5'],
            'hyphen range' => ['Excellent (4-5)', 'Excellent', '5', '4–5'],
            'em dash range' => ['Very good (3—4)', 'Very good', '4', '3–4'],
            'word range' => ['Fair (1 to 2)', 'Fair', '2', '1–2'],
            'decimal' => ['Good (1.5)', 'Good', '1.5', null],
            'trailing zero' => ['Excellent (2.0)', 'Excellent', '2', null],
            'decimal comma' => ['Good (1,5)', 'Good', '1.5', null],
            'decimal range' => ['Good (1.5–2.0)', 'Good', '2', '1.5–2'],
            'spaces inside' => ['Excellent ( 4 – 5 )', 'Excellent', '5', '4–5'],
            'reversed range' => ['Excellent (5–4)', 'Excellent', '5', '4–5'],
            'no label' => ['(3)', 'Level 3', '3', null],
            'zero' => ['None (0)', 'None', '0', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('levelHeaders')]
    public function test_level_headers(string $header, string $label, string $marks, ?string $range): void
    {
        $this->assertSame(
            ['label' => $label, 'marks' => $marks, 'range' => $range],
            $this->parser()->level($header),
        );
    }

    public static function notLevels(): array
    {
        return [['Criteria'], ['Weight'], ['Poor'], ['Total (marks)'], ['5'], ['Excellent (high)'], ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notLevels')]
    public function test_these_are_not_level_headers(string $header): void
    {
        $this->assertNull($this->parser()->level($header));
    }

    /*
    | Layouts
    */

    public function test_a_table_without_a_title_row_gets_a_numbered_name(): void
    {
        $path = $this->workbook([
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Design', 2, 'weak', 'solid'],
        ]);

        $draft = $this->parser()->parse($path);

        $this->assertSame('Section 1', $draft['sections'][0]['title']);
        $this->assertSame('manual', $draft['sections'][0]['mode']);
    }

    public function test_extra_blank_rows_and_a_document_title_do_not_confuse_it(): void
    {
        $path = $this->workbook([
            ['Marking Rubric 2026'],
            [],
            [],
            ['Teamwork'],
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Collaboration', 5, 'alone', 'together'],
            [],
            [],
            [],
            ['Report'],
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Writing', 5, 'unclear', 'clear'],
        ]);

        $sections = $this->parser()->parse($path)['sections'];

        $this->assertSame(['Teamwork', 'Report'], array_column($sections, 'title'));
        $this->assertSame('Collaboration', $sections[0]['criteria'][0]['name']);
        $this->assertSame('Writing', $sections[1]['criteria'][0]['name']);
    }

    public function test_a_table_ends_at_its_first_blank_row(): void
    {
        $path = $this->workbook([
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['One', 1, 'a', 'b'],
            [],
            ['Notes below the table that are not a criterion', 99, 'x', 'y'],
        ]);

        $this->assertCount(1, $this->parser()->parse($path)['sections'][0]['criteria']);
    }

    public function test_tables_on_separate_sheets_are_all_read(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('One')->fromArray([['Criteria', 'Weight', 'Poor (1)', 'Good (3)'], ['A', 1, 'a', 'b']]);
        $book->createSheet()->setTitle('Two')->fromArray([['Criteria', 'Weight', 'Poor (1)', 'Good (3)'], ['B', 1, 'a', 'b']]);

        $sections = $this->parser()->parse($this->save($book))['sections'];

        $this->assertSame(['A', 'B'], array_map(fn ($s) => $s['criteria'][0]['name'], $sections));
        $this->assertSame(['Section 1', 'Section 2'], array_column($sections, 'title'));
    }

    public function test_a_weight_written_as_a_percentage_or_with_a_comma_is_read(): void
    {
        $path = $this->workbook([
            ['Criteria', 'Weight (%)', 'Poor (1)', 'Good (3)'],
            ['A', '20%', 'a', 'b'],
            ['B', '1,5', 'a', 'b'],
            ['C', 'n/a', 'a', 'b'],
        ]);

        $criteria = $this->parser()->parse($path);

        $this->assertSame('20', $criteria['sections'][0]['criteria'][0]['weight']);
        $this->assertSame('1.5', $criteria['sections'][0]['criteria'][1]['weight']);
        $this->assertNull($criteria['sections'][0]['criteria'][2]['weight']);
        $this->assertStringContainsString('no weight', implode(' ', $criteria['warnings']));
    }

    public function test_a_missing_descriptor_is_flagged_but_kept(): void
    {
        $path = $this->workbook([
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['A', 1, 'a', null],
        ]);

        $draft = $this->parser()->parse($path);

        $this->assertSame('', $draft['sections'][0]['criteria'][0]['levels'][1]['descriptor']);
        $this->assertStringContainsString('no description for “Good”', implode(' ', $draft['warnings']));
    }

    public function test_a_formula_is_not_evaluated(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Criteria', 'Weight', 'Poor (1)', 'Good (3)'], ['A', 1, '', '']]);
        $sheet->setCellValue('C2', '=1+1');
        $sheet->setCellValue('D2', '=HYPERLINK("http://example.test","click")');

        $levels = $this->parser()->parse($this->save($book))['sections'][0]['criteria'][0]['levels'];

        $this->assertSame('=1+1', $levels[0]['descriptor']);
        $this->assertStringStartsWith('=HYPERLINK', $levels[1]['descriptor']);
    }

    /*
    | Files that are not usable
    */

    public function test_a_file_with_no_rubric_table_is_refused_in_plain_words(): void
    {
        $path = $this->workbook([['Name', 'Score'], ['A', 5]]);

        $this->expectException(RubricException::class);
        $this->expectExceptionMessage('No rubric table was found');

        $this->parser()->parse($path);
    }

    public function test_something_that_is_not_a_spreadsheet_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'this is not a spreadsheet');
        $this->temp[] = $path;

        $this->expectException(RubricException::class);
        $this->expectExceptionMessage('could not be read');

        $this->parser()->parse($path);
    }

    public function test_an_oversized_sheet_is_refused(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1001', 'x')->setCellValue('A1', 'x');

        $this->expectException(RubricException::class);
        $this->expectExceptionMessage('larger than a rubric should be');

        $this->parser()->parse($this->save($book));
    }
}
