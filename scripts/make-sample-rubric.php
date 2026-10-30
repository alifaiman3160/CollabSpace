<?php

/*
 * Builds docs/samples/MarkingRubric.xlsx, the sample the rubric import is
 * tested against and the one to try it with by hand.
 *
 *   php scripts/make-sample-rubric.php
 *
 * It reproduces the structure of the real marking rubric as described, so the
 * parser is exercised on the awkward parts of it:
 *
 *   - one sheet, two tables, TWO blank rows between them
 *   - title rows with an en dash, an ampersand and a declared total: "(30 marks)"
 *   - first-column headers that are not "Criterion": "Report Section" and
 *     "Peer-assessment criterion"
 *   - CLO2 (30 marks): seven weights 0.4, 1, 1, 1.6, 0.8, 0.6, 0.6 summing to 6.0,
 *     top level 5, so marks = level x weight and the maximum is 6.0 x 5 = 30
 *   - CLO4 (10 marks): five criteria, weight 2 each, top level 2.0, so marks =
 *     level only and the maximum is 5 x 2.0 = 10 (multiplying would say 20)
 *   - a "Total" row under each table, which is arithmetic and not a criterion
 *
 * The descriptors are written for this sample. When the real file is to hand,
 * drop it over this one and run the tests; they are written against the
 * structure above, not against this wording.
 */

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book = new Spreadsheet;
$sheet = $book->getActiveSheet();
$sheet->setTitle('Rubric');

$row = 1;

$table = function (string $title, array $header, array $criteria, array $total, int $gap) use ($sheet, &$row): void {
    $sheet->setCellValue("A{$row}", $title);
    $sheet->mergeCells("A{$row}:".chr(64 + count($header))."{$row}");
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
    $row++;

    foreach ($header as $i => $text) {
        $sheet->setCellValue(chr(65 + $i).$row, $text);
        $sheet->getStyle(chr(65 + $i).$row)->getFont()->setBold(true);
    }
    $row++;

    foreach ([...$criteria, $total] as $criterion) {
        foreach ($criterion as $i => $text) {
            $cell = chr(65 + $i).$row;
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }
        $row++;
    }

    $row += $gap;   // blank rows between tables
};

$table(
    'CLO2 – Report Quality & Presentation (30 marks)',
    ['Report Section', 'Weight', 'Poor (1)', 'Fair (2)', 'Good (3)', 'Excellent (4–5)'],
    [
        ['Abstract & introduction', 0.4, 'Missing or unclear.', 'Present but incomplete.', 'Clear and complete.', 'Clear, concise and engaging.'],
        ['Problem statement and objectives', 1, 'Unclear or missing.', 'Stated but vague.', 'Clear and mostly measurable.', 'Specific, measurable and tied to the users.'],
        ['Analysis and requirements', 1, 'Requirements missing.', 'Some requirements, with gaps.', 'Requirements complete and sensible.', 'Complete, prioritised and traceable.'],
        ['Design', 1.6, 'Diagrams missing or incorrect.', 'Some diagrams, with errors.', 'Correct diagrams that match the requirements.', 'Complete and consistent across all diagrams.'],
        ['Implementation', 0.8, 'Little working software.', 'Partly working.', 'Working software.', 'Complete, well structured software.'],
        ['Testing', 0.6, 'No testing.', 'Limited testing.', 'Documented tests.', 'Thorough, documented testing.'],
        ['Conclusion & references', 0.6, 'Missing; no references.', 'Brief; few references.', 'Clear; references present.', 'Insightful; consistently referenced.'],
    ],
    ['Total', 6.0, '', '', '', ''],
    2,
);

$table(
    'CLO4 – Teamwork (10 marks)',
    ['Peer-assessment criterion', 'Weight', 'Poor (0.5)', 'Fair (1.0)', 'Good (1.5)', 'Excellent (2.0)'],
    [
        ['Contribution to tasks', 2, 'Completed few of the tasks assigned.', 'Completed about half.', 'Completed most.', 'Completed nearly all, and took on more.'],
        ['Reliability and deadlines', 2, 'Often late or missed.', 'Sometimes late.', 'Mostly on time.', 'Consistently on time.'],
        ['Communication', 2, 'Rarely took part in discussion.', 'Took part when asked.', 'Took part regularly.', 'Took part regularly and kept others informed.'],
        ['Collaboration', 2, 'Worked alone.', 'Worked with others occasionally.', 'Worked with others on shared work.', 'Regularly helped build and improve shared work.'],
        ['Initiative and problem solving', 2, 'Waited to be told what to do.', 'Acted on request.', 'Often took the initiative.', 'Regularly found and solved problems unprompted.'],
    ],
    ['Total', 10, '', '', '', ''],
    0,
);

$sheet->getColumnDimension('A')->setWidth(34);
$sheet->getColumnDimension('B')->setWidth(9);
foreach (['C', 'D', 'E', 'F'] as $col) {
    $sheet->getColumnDimension($col)->setWidth(30);
}

(new Xlsx($book))->save(__DIR__.'/../docs/samples/MarkingRubric.xlsx');

echo "Wrote docs/samples/MarkingRubric.xlsx\n";
