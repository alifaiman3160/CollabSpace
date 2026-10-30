<?php

namespace App\Support\Rubric;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads a marking rubric out of an .xlsx file.
 *
 * A sheet may hold several tables stacked one above another, separated by blank
 * rows. Each is recognised by its header row: a row carrying two or more level
 * headers such as "Poor (1)", "Good (1.5)", "Excellent (4–5)" or "Excellent
 * (2.0)". The row directly above it, if it holds a single cell, is the table's
 * title. Under the header, one row per criterion until the next blank row.
 *
 * The result is a draft, plain arrays that nothing has been saved from. The
 * lecturer reviews and corrects it before anything is written.
 *
 * Everything in the file is treated as text to be shown, never as something to
 * run: formulas are not evaluated, cells are read as the strings the sheet
 * displays, and lengths are capped.
 */
class RubricParser
{
    /** "Excellent (4–5)", "Good (1.5)", "Poor (1)", "Fair (1 to 2)". */
    private const LEVEL = '/^(?<label>.*?)\s*\(\s*(?<low>\d+(?:[.,]\d+)?)(?:\s*(?:[–—\-]|to)\s*(?<high>\d+(?:[.,]\d+)?))?\s*\)\s*$/iu';

    private const WEIGHT_HEADER = '/weight|bobot|wt\b|%|percent|mark/i';

    /** Sections about recorded teamwork evidence default to system-suggested; everything else to manual. */
    private const SUGGESTED_TITLE = '/clo\s*4|team|collaborat|contribut|participat/i';

    /** A totals row ("Total", "Sub-total", "Jumlah") is arithmetic, not a criterion. */
    private const TOTAL_ROW = '/^\s*(sub[\s\-]?)?(total|jumlah)\b/i';

    /** "(30 marks)", "30 marks", "Total: 10 marks": the last such figure in a title. */
    private const DECLARED_TOTAL = '/(\d+(?:[.,]\d+)?)\s*(?:marks?|markah)\b/iu';

    private const MAX_ROWS = 1000;

    private const MAX_COLUMNS = 40;

    /**
     * @return array{
     *     name: string,
     *     sheet: string,
     *     sections: list<array{title: string, mode: string, criteria: list<array{name: string, weight: ?string, mode: string, levels: list<array{label: string, marks: string, range: ?string, descriptor: string}>}>}>,
     *     warnings: list<string>
     * }
     *
     * @throws RubricException  when the file is unreadable or holds no rubric table
     */
    public function parse(string $path, string $fileName = 'rubric.xlsx'): array
    {
        $book = $this->load($path);

        $sections = [];
        $warnings = [];
        $sheetName = '';

        foreach ($book->getWorksheetIterator() as $sheet) {
            $found = $this->parseSheet($sheet, count($sections), $warnings);

            if ($found !== [] && $sheetName === '') {
                $sheetName = $sheet->getTitle();
            }

            array_push($sections, ...$found);
        }

        $book->disconnectWorksheets();

        if ($sections === []) {
            throw new RubricException(
                'No rubric table was found in this file. Each table needs a header row with performance levels '
                .'such as “Poor (1)” and “Excellent (4–5)”.'
            );
        }

        return [
            'name' => $this->nameFrom($fileName),
            'sheet' => $sheetName,
            'sections' => $sections,
            'warnings' => $warnings,
        ];
    }

    /** @throws RubricException */
    private function load(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(false);   // styles are needed to read a weight shown as "20%"
            $reader->setReadEmptyCells(false);

            return $reader->load($path);
        } catch (Throwable) {
            throw new RubricException('This file could not be read as an .xlsx spreadsheet. Check that it opens in Excel and try again.');
        }
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function parseSheet(Worksheet $sheet, int $already, array &$warnings): array
    {
        if ($sheet->getHighestDataRow() > self::MAX_ROWS
            || \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::MAX_COLUMNS) {
            throw new RubricException('The sheet “'.$sheet->getTitle().'” is larger than a rubric should be.');
        }

        // No formula evaluation; cells come back as the text the sheet displays
        // ("20%" stays "20%"), not as the raw number behind it.
        $raw = $sheet->toArray(null, false, true, false);

        $rows = array_map(
            fn (array $row) => array_map(fn ($cell) => $this->clean($cell), $row),
            $raw,
        );

        $sections = [];
        $headers = [];

        foreach ($rows as $i => $row) {
            if (count($this->levelColumns($row)) >= 2) {
                $headers[] = $i;
            }
        }

        foreach ($headers as $n => $h) {
            $title = $this->titleAbove($rows, $h);
            $end = $headers[$n + 1] ?? count($rows);

            $sections[] = $this->parseTable(
                $rows,
                $h,
                $end,
                $title ?? 'Section '.($already + $n + 1),
                $sheet->getTitle(),
                $warnings,
            );
        }

        return $sections;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function parseTable(array $rows, int $h, int $end, string $title, string $sheet, array &$warnings): array
    {
        $header = $rows[$h];
        $levelCols = $this->levelColumns($header);
        $firstLevel = array_key_first($levelCols);

        $weightCol = null;
        $criterionCol = null;

        foreach ($header as $col => $text) {
            if ($text === '' || isset($levelCols[$col])) {
                continue;
            }

            if ($weightCol === null && preg_match(self::WEIGHT_HEADER, $text)) {
                $weightCol = $col;
            } elseif ($criterionCol === null) {
                $criterionCol = $col;
            }
        }

        $criterionCol ??= 0;

        $criteria = [];
        $mode = preg_match(self::SUGGESTED_TITLE, $title) ? 'suggested' : 'manual';

        for ($r = $h + 1; $r < $end; $r++) {
            $row = $rows[$r];

            if ($this->blank($row)) {
                break;   // the table ends at its first blank row
            }

            // A single-cell row directly above the next header is that table's title.
            if ($this->isTitleRow($row) && $r + 1 < count($rows) && count($this->levelColumns($rows[$r + 1])) >= 2) {
                break;
            }

            $name = $row[$criterionCol] ?? '';

            if ($name === '') {
                $warnings[] = "“{$title}”: a row without a criterion name was skipped.";

                continue;
            }

            if (preg_match(self::TOTAL_ROW, $name)) {
                continue;   // the sheet's own sum, not something to mark
            }

            $weight = $weightCol === null ? null : $this->number($row[$weightCol] ?? '');

            if ($weight === null) {
                $warnings[] = "“{$title}”, “{$name}”: no weight was found. Enter one on the next screen.";
            }

            $levels = [];

            foreach ($levelCols as $col => $level) {
                $descriptor = $row[$col] ?? '';

                if ($descriptor === '') {
                    $warnings[] = "“{$title}”, “{$name}”: no description for “{$level['label']}”.";
                }

                $levels[] = [
                    'label' => $level['label'],
                    'marks' => $level['marks'],
                    'range' => $level['range'],
                    'descriptor' => $descriptor,
                ];
            }

            $criteria[] = [
                'name' => $name,
                'weight' => $weight,
                'mode' => $mode,
                // A starting point from the wording only; the review screen says so.
                'evidence_type' => \App\Support\Marking\EvidenceType::guess($name),
                'levels' => $levels,
            ];
        }

        if ($criteria === []) {
            $warnings[] = "“{$title}” has a header but no criteria under it.";
        }

        $declared = $this->declaredTotal($title);
        $maxima = RubricScoring::maxima($criteria);
        $scoring = RubricScoring::preselect($declared, $maxima);

        if ($criteria !== [] && $scoring === null) {
            $warnings[] = RubricScoring::problem(null, $declared, $maxima, $title);
        }

        return [
            'title' => $title,
            'mode' => $mode,
            'declared_total' => $declared === null ? null : RubricScoring::trim($declared),
            'scoring' => $scoring,
            'criteria' => $criteria,
        ];
    }

    /**
     * Columns of a row that are level headers, keyed by column index.
     *
     * @param  list<string>  $row
     * @return array<int, array{label: string, marks: string, range: ?string}>
     */
    public function levelColumns(array $row): array
    {
        $levels = [];

        foreach ($row as $col => $text) {
            if ($text !== '' && ($parsed = $this->level($text))) {
                $levels[$col] = $parsed;
            }
        }

        return $levels;
    }

    /**
     * "Excellent (4–5)" => label Excellent, marks 5 (the upper bound), range "4–5".
     *
     * @return array{label: string, marks: string, range: ?string}|null
     */
    public function level(string $header): ?array
    {
        if (! preg_match(self::LEVEL, $header, $m)) {
            return null;
        }

        $low = (float) str_replace(',', '.', $m['low']);
        $high = isset($m['high']) && $m['high'] !== '' ? (float) str_replace(',', '.', $m['high']) : null;

        if ($high !== null && $high < $low) {
            [$low, $high] = [$high, $low];
        }

        $label = trim($m['label']) !== '' ? trim($m['label']) : 'Level '.$this->format($high ?? $low);

        return [
            'label' => mb_substr($label, 0, 60),
            'marks' => $this->format($high ?? $low),
            'range' => $high !== null && $high !== $low ? $this->format($low).'–'.$this->format($high) : null,
        ];
    }

    /** The total a title declares, "(30 marks)" => 30, or null when it declares none. */
    private function declaredTotal(string $title): ?float
    {
        if (! preg_match_all(self::DECLARED_TOTAL, $title, $m)) {
            return null;
        }

        return (float) str_replace(',', '.', end($m[1]));
    }

    /** @param  list<list<string>>  $rows */
    private function titleAbove(array $rows, int $h): ?string
    {
        $above = $rows[$h - 1] ?? null;

        return $above !== null && $this->isTitleRow($above)
            ? current(array_filter($above, fn ($c) => $c !== ''))
            : null;
    }

    /** @param  list<string>  $row */
    private function isTitleRow(array $row): bool
    {
        return count(array_filter($row, fn ($c) => $c !== '')) === 1;
    }

    /** @param  list<string>  $row */
    private function blank(array $row): bool
    {
        return count(array_filter($row, fn ($c) => $c !== '')) === 0;
    }

    private function clean(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        if (is_bool($cell)) {
            return $cell ? 'TRUE' : 'FALSE';
        }

        // Collapse runs of whitespace but keep the text otherwise as written.
        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace(["\r\n", "\r"], "\n", (string) $cell)));
    }

    /** "20%", "2.0", "1,5" => a plain decimal string, or null when it is not a number. */
    private function number(string $text): ?string
    {
        $text = trim(str_replace(['%', ' '], '', $text));
        $text = str_replace(',', '.', $text);

        return is_numeric($text) ? $this->format((float) $text) : null;
    }

    private function format(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function nameFrom(string $fileName): string
    {
        $name = trim(preg_replace('/[_\-]+|\s+/', ' ', pathinfo($fileName, PATHINFO_FILENAME)));
        $name = trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name));

        return $name !== '' ? mb_substr($name, 0, 120) : 'Marking rubric';
    }
}
