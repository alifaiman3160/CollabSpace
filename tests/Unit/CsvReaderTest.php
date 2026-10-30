<?php

namespace Tests\Unit;

use App\Support\Formation\CsvReader;
use App\Support\Formation\FormationException;
use PHPUnit\Framework\TestCase;

/**
 * The CSV reader, against the files lecturers actually have.
 *
 * Google Forms writes plain UTF-8 with commas, but by the time a file is
 * uploaded it has usually been opened and re-saved in Excel, which adds a
 * byte-order mark, writes Windows-1252, uses semicolons in some regions, or
 * calls UTF-16 "Unicode text". Each of those is a file a lecturer will
 * reasonably upload and not know is unusual.
 */
class CsvReaderTest extends TestCase
{
    /** @var list<string> */
    protected array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    protected function read(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return (new CsvReader())->read($path);
    }

    protected function refuses(string $contents, string $messageContains): void
    {
        try {
            $this->read($contents);
        } catch (FormationException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());

            return;
        }

        $this->fail('Expected the file to be refused.');
    }

    /*
    | The ordinary file
    */

    public function test_a_plain_file_is_read(): void
    {
        $read = $this->read("Group name,Member 1\nAlpha,A21EC0001\nBeta,A21EC0002\n");

        $this->assertSame(['Group name', 'Member 1'], $read['headers']);
        $this->assertSame(',', $read['delimiter']);
        $this->assertCount(2, $read['rows']);
        $this->assertSame(['Alpha', 'A21EC0001'], $read['rows'][0]['cells']);
    }

    public function test_row_numbers_match_the_spreadsheet(): void
    {
        $read = $this->read("Group,Member\nAlpha,A\nBeta,B\n");

        // The heading is row 1, so the first response is row 2: what a lecturer
        // sees in the sheet, and what the review screen quotes back.
        $this->assertSame([2, 3], array_column($read['rows'], 'n'));
    }

    public function test_cells_are_trimmed(): void
    {
        $read = $this->read("Group,Member\n  Alpha  ,  A21EC0001\t\n");

        $this->assertSame(['Alpha', 'A21EC0001'], $read['rows'][0]['cells']);
    }

    /*
    | What Excel does to a file
    */

    public function test_a_byte_order_mark_does_not_end_up_in_the_first_heading(): void
    {
        $read = $this->read("\xEF\xBB\xBFGroup name,Member 1\nAlpha,A21EC0001\n");

        // Without handling it, the heading would be "\xEF\xBB\xBFGroup name" and
        // never look like a group column.
        $this->assertSame('Group name', $read['headers'][0]);
    }

    public function test_a_semicolon_file_is_read(): void
    {
        $read = $this->read("Group name;Member 1;Member 2\nAlpha;A21EC0001;A21EC0002\n");

        $this->assertSame(';', $read['delimiter']);
        $this->assertSame(['Alpha', 'A21EC0001', 'A21EC0002'], $read['rows'][0]['cells']);
    }

    public function test_a_tab_separated_file_is_read(): void
    {
        $read = $this->read("Group name\tMember 1\nAlpha\tA21EC0001\n");

        $this->assertSame("\t", $read['delimiter']);
        $this->assertSame(['Alpha', 'A21EC0001'], $read['rows'][0]['cells']);
    }

    public function test_a_commas_inside_quotes_do_not_choose_the_delimiter(): void
    {
        $read = $this->read("\"Group, name\";Member 1\nAlpha;A21EC0001\n");

        $this->assertSame(';', $read['delimiter']);
        $this->assertSame(['Group, name', 'Member 1'], $read['headers']);
    }

    public function test_a_windows_1252_file_keeps_its_accents(): void
    {
        $text = "Group name,Member 1\nCaf\xE9 Team,Jos\xE9\n";   // é as a single byte

        $read = $this->read($text);

        $this->assertSame('Café Team', $read['rows'][0]['cells'][0]);
        $this->assertSame('José', $read['rows'][0]['cells'][1]);
    }

    public function test_a_utf16_file_is_read(): void
    {
        $text = "\xFF\xFE".mb_convert_encoding("Group name\tMember 1\nAlpha\tA21EC0001\n", 'UTF-16LE', 'UTF-8');

        $read = $this->read($text);

        $this->assertSame(['Group name', 'Member 1'], $read['headers']);
        $this->assertSame(['Alpha', 'A21EC0001'], $read['rows'][0]['cells']);
    }

    public function test_windows_and_old_mac_line_endings_both_work(): void
    {
        foreach (["Group,Member\r\nAlpha,A\r\n", "Group,Member\rAlpha,A\r"] as $text) {
            $this->assertSame(['Alpha', 'A'], $this->read($text)['rows'][0]['cells']);
        }
    }

    /*
    | Quoting
    */

    public function test_a_quoted_cell_can_span_lines(): void
    {
        // A paragraph answer listing members one per line.
        $read = $this->read("Group,Members\nAlpha,\"A21EC0001\nA21EC0002\nA21EC0003\"\nBeta,A21EC0004\n");

        $this->assertCount(2, $read['rows']);
        $this->assertSame("A21EC0001\nA21EC0002\nA21EC0003", $read['rows'][0]['cells'][1]);
    }

    public function test_a_doubled_quote_is_a_quote(): void
    {
        $read = $this->read("Group,Member\n\"The \"\"A\"\" Team\",A21EC0001\n");

        $this->assertSame('The "A" Team', $read['rows'][0]['cells'][0]);
    }

    public function test_a_comma_inside_quotes_stays_in_the_cell(): void
    {
        $read = $this->read("Group,Member\n\"Smith, Jones & Co\",A21EC0001\n");

        $this->assertSame('Smith, Jones & Co', $read['rows'][0]['cells'][0]);
    }

    public function test_a_backslash_is_not_an_escape_character(): void
    {
        // A Windows path or a name ending in a backslash must not swallow the
        // quote that closes the cell.
        $read = $this->read("Group,Member\n\"C:\\temp\\\",A21EC0001\nBeta,A21EC0002\n");

        $this->assertCount(2, $read['rows']);
        $this->assertSame('C:\\temp\\', $read['rows'][0]['cells'][0]);
    }

    /*
    | Messy shapes
    */

    public function test_blank_lines_are_skipped_but_still_counted(): void
    {
        $read = $this->read("Group,Member\n\nAlpha,A\n\n\nBeta,B\n");

        // Beta is on row 6 of the file, so that is what the lecturer is told.
        $this->assertSame([3, 6], array_column($read['rows'], 'n'));
    }

    public function test_a_row_of_only_commas_is_blank(): void
    {
        $read = $this->read("Group,Member,Member 2\nAlpha,A,B\n,,\nBeta,C,D\n");

        $this->assertCount(2, $read['rows']);
    }

    public function test_short_rows_are_padded(): void
    {
        $read = $this->read("Group,Member 1,Member 2,Member 3\nAlpha,A\n");

        $this->assertSame(['Alpha', 'A', '', ''], $read['rows'][0]['cells']);
    }

    public function test_a_row_wider_than_the_heading_widens_it(): void
    {
        // An unquoted comma in a cell pushes everything right. Losing the extra
        // cells would lose members, so the heading grows to meet them.
        $read = $this->read("Group,Member\nAlpha,A,B,C\n");

        $this->assertSame(['Group', 'Member', 'Column 3', 'Column 4'], $read['headers']);
        $this->assertSame(['Alpha', 'A', 'B', 'C'], $read['rows'][0]['cells']);
    }

    public function test_a_blank_heading_gets_a_name(): void
    {
        $read = $this->read("Group,,Member\nAlpha,x,A\n");

        $this->assertSame(['Group', 'Column 2', 'Member'], $read['headers']);
    }

    public function test_a_file_with_no_final_newline_is_read(): void
    {
        $this->assertCount(1, $this->read("Group,Member\nAlpha,A")['rows']);
    }

    /*
    | Files that are not usable
    */

    public function test_an_empty_file_is_refused(): void
    {
        $this->refuses('', 'empty');
        $this->refuses("  \n \n", 'empty');
    }

    public function test_a_file_with_only_headings_is_refused(): void
    {
        $this->refuses("Group name,Member 1\n", 'no responses');
    }

    public function test_a_binary_file_is_refused(): void
    {
        $this->refuses("PK\x03\x04\x14\x00\x00\x00binary\x00data", 'does not look like a CSV');
    }

    public function test_far_too_many_rows_are_refused(): void
    {
        $rows = "Group,Member\n".str_repeat("Alpha,A21EC0001\n", CsvReader::MAX_ROWS + 1);

        $this->refuses($rows, 'more than '.CsvReader::MAX_ROWS);
    }

    public function test_exactly_the_maximum_is_accepted(): void
    {
        $rows = "Group,Member\n".str_repeat("Alpha,A21EC0001\n", CsvReader::MAX_ROWS);

        $this->assertCount(CsvReader::MAX_ROWS, $this->read($rows)['rows']);
    }

    public function test_far_too_many_columns_are_refused(): void
    {
        $header = implode(',', array_map(fn ($n) => "Column{$n}", range(1, CsvReader::MAX_COLUMNS + 1)));

        $this->refuses($header."\nx\n", 'columns');
    }
}
