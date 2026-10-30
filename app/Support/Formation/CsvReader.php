<?php

namespace App\Support\Formation;

/**
 * Reads a CSV export into headers and rows, tolerating what real exports do.
 *
 * Google Forms writes plain UTF-8 with commas, but the file a lecturer uploads
 * has usually been through something else first: opened and re-saved in Excel,
 * which adds a byte-order mark or writes Windows-1252, or, in some regional
 * settings, semicolons instead of commas, or UTF-16 under the name "Unicode
 * text". None of those should turn into a confusing failure, and none should
 * turn into silently wrong data either, so each is handled explicitly and
 * anything that cannot be handled is refused with a plain explanation.
 *
 * Reads only. What the cells mean is somebody else's job.
 */
class CsvReader
{
    /** More than this is a spreadsheet of something else, not a class's groups. */
    public const MAX_ROWS = 500;

    public const MAX_COLUMNS = 40;

    /**
     * @return array{headers: list<string>, rows: list<array{n: int, cells: list<string>}>, delimiter: string}
     *
     * @throws FormationException
     */
    public function read(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            throw new FormationException('That file is empty.');
        }

        $text = $this->toUtf8($raw);

        // A real CSV never contains a NUL. One that does is a spreadsheet, a
        // PDF or an image that has been renamed.
        if (str_contains($text, "\0")) {
            throw new FormationException('That does not look like a CSV file. Export the form responses as CSV and upload that.');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $delimiter = $this->delimiter($text);

        $records = $this->parse($text, $delimiter);

        if ($records === []) {
            throw new FormationException('That file has no rows in it.');
        }

        $header = array_shift($records);
        $width = max(
            count($header['cells']),
            ...array_map(fn (array $r) => count($r['cells']), $records ?: [['cells' => []]]),
        );

        if ($width > self::MAX_COLUMNS) {
            throw new FormationException('That file has more than '.self::MAX_COLUMNS.' columns, which is far more than a group form produces.');
        }

        $headers = [];
        for ($i = 0; $i < $width; $i++) {
            $label = trim((string) ($header['cells'][$i] ?? ''));
            $headers[] = $label === '' ? 'Column '.($i + 1) : $label;
        }

        if ($records === []) {
            throw new FormationException('That file has a header row but no responses under it.');
        }

        if (count($records) > self::MAX_ROWS) {
            throw new FormationException('That file has more than '.self::MAX_ROWS.' responses. Split it, or check it is the right export.');
        }

        $rows = array_map(fn (array $r) => [
            'n' => $r['n'],
            'cells' => array_pad($r['cells'], $width, ''),
        ], $records);

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    protected function toUtf8(string $raw): string
    {
        // UTF-16, as Excel's "Unicode text" produces. Has to come first: it is
        // full of NULs and would fail the binary check below.
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Not valid UTF-8 almost always means Windows-1252, which is what Excel
        // on Windows writes by default. Reading it as UTF-8 would turn every
        // accented letter into a replacement character.
        if (! mb_check_encoding($raw, 'UTF-8')) {
            return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    /** Comma, semicolon or tab, by which one the header line uses most. */
    protected function delimiter(string $text): string
    {
        $first = strtok($text, "\n");

        // Delimiters inside quoted cells must not vote.
        $outside = preg_replace('/"[^"]*"/', '', (string) $first);

        $counts = [
            ',' => substr_count($outside, ','),
            ';' => substr_count($outside, ';'),
            "\t" => substr_count($outside, "\t"),
        ];

        arsort($counts);

        return array_key_first($counts) !== null && reset($counts) > 0 ? array_key_first($counts) : ',';
    }

    /**
     * @return list<array{n: int, cells: list<string>}>
     */
    protected function parse(string $text, string $delimiter): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $records = [];
        $n = 0;

        // The empty escape character gives RFC 4180 behaviour: a doubled quote
        // inside a quoted cell is a quote, and a backslash is just a backslash.
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $n++;

            $cells = array_map(fn ($c) => trim((string) $c), $row);

            // A blank line comes back as [null]. Dropped, but still counted, so
            // the row numbers shown to the lecturer match their spreadsheet.
            if (count(array_filter($cells, fn ($c) => $c !== '')) === 0) {
                continue;
            }

            $records[] = ['n' => $n, 'cells' => $cells];
        }

        fclose($stream);

        return $records;
    }
}
