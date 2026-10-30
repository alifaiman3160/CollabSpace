<?php

namespace Tests\Unit;

use App\Support\Rubric\RubricParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * The awkward parts of the real marking rubric, each on its own: first-column
 * headers that are not "Criterion", an en dash, an ampersand and a declared total
 * in a title, two blank rows between tables, and a totals row under a table.
 */
class RubricParserRealFileTest extends TestCase
{
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    /** @param  list<list<mixed>>  $rows */
    protected function parse(array $rows): array
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) || is_float($value) ? 'n' : 's');
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'rub').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->temp[] = $path;

        return (new RubricParser)->parse($path);
    }

    public function test_a_totals_row_is_not_a_criterion(): void
    {
        $draft = $this->parse([
            ['Report Section', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Design', 1.6, 'weak', 'solid'],
            ['Testing', 0.6, 'none', 'some'],
            ['Total', 2.2, null, null],
        ]);

        $this->assertSame(['Design', 'Testing'], array_column($draft['sections'][0]['criteria'], 'name'));
        // The only note is that the title states no total; the totals row itself is not mentioned.
        $this->assertStringNotContainsString('Total', implode(' ', $draft['warnings']));
    }

    public function test_sub_total_and_malay_totals_rows_are_skipped_too(): void
    {
        $draft = $this->parse([
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Design', 1, 'a', 'b'],
            ['Sub-total', 1, null, null],
            ['Jumlah', 1, null, null],
        ]);

        $this->assertSame(['Design'], array_column($draft['sections'][0]['criteria'], 'name'));
    }

    public function test_a_criterion_that_merely_starts_with_total_is_kept(): void
    {
        $draft = $this->parse([
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Totality of the evidence', 1, 'a', 'b'],
        ]);

        $this->assertSame(['Totality of the evidence'], array_column($draft['sections'][0]['criteria'], 'name'));
    }

    public function test_first_column_headers_other_than_criterion_are_fine(): void
    {
        foreach (['Report Section', 'Peer-assessment criterion', 'Aspect', 'Item', 'Kriteria'] as $header) {
            $criteria = $this->parse([
                [$header, 'Weight', 'Poor (1)', 'Good (3)'],
                ['Design', 2, 'weak', 'solid'],
            ])['sections'][0]['criteria'];

            $this->assertSame('Design', $criteria[0]['name'], $header);
            $this->assertSame('2', $criteria[0]['weight'], $header);
        }
    }

    public function test_titles_with_an_en_dash_an_ampersand_and_a_total_are_kept_as_written(): void
    {
        $section = $this->parse([
            ['CLO2 – Report Quality & Presentation (30 marks)'],
            ['Report Section', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Design', 2, 'weak', 'solid'],
        ])['sections'][0];

        $this->assertSame('CLO2 – Report Quality & Presentation (30 marks)', $section['title']);
        $this->assertSame('30', $section['declared_total']);
    }

    public function test_a_title_ending_in_a_bare_number_in_brackets_is_still_a_title(): void
    {
        $section = $this->parse([
            ['Teamwork (10)'],
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['Design', 2, 'weak', 'solid'],
        ])['sections'][0];

        $this->assertSame('Teamwork (10)', $section['title']);
    }

    public function test_two_blank_rows_between_tables_still_separate_them(): void
    {
        $sections = $this->parse([
            ['First (6 marks)'],
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['A', 1, 'a', 'b'],
            [],
            [],
            ['Second (4 marks)'],
            ['Criteria', 'Weight', 'Poor (1)', 'Good (3)'],
            ['B', 1, 'a', 'b'],
        ])['sections'];

        $this->assertSame(['First (6 marks)', 'Second (4 marks)'], array_column($sections, 'title'));
        $this->assertSame(['A'], array_column($sections[0]['criteria'], 'name'));
        $this->assertSame(['B'], array_column($sections[1]['criteria'], 'name'));
    }
}
