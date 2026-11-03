<?php

namespace App\Support\Submissions;

use App\Models\Assessment;
use App\Models\Document;
use App\Models\Group;
use App\Models\User;
use App\Support\DocumentPrintBody;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the one PDF of a submission with dompdf, through the same print layout and body preparation as the single
 * document "Download as PDF": a cover page, then each chosen document on a new page, with continuous page numbers.
 */
class SubmissionPdf
{
    public function __construct(protected DocumentPrintBody $print) {}

    /**
     * @param  Collection<int, Document>  $documents  in the order they go into the PDF
     * @return array{output: string, pages: ?int}
     */
    public function render(Group $group, Assessment $assessment, Collection $documents, User $by, CarbonInterface $at, bool $late): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $group->loadMissing('subject', 'section', 'members');

        $sections = $documents->map(fn (Document $document) => [
            'title' => $document->title,
            'body' => $this->print->render((string) $document->content, $document),
        ])->all();

        $pdf = Pdf::setOption([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isHtml5ParserEnabled' => true,
        ])->setPaper('a4')->loadView('submissions.pdf', [
            'group' => $group,
            'assessment' => $assessment,
            'by' => $by,
            'at' => $at->copy()->setTimezone(config('app.timezone')),
            'late' => $late,
            'sections' => $sections,
        ]);

        $output = $pdf->output();

        if ($output === '' || ! str_starts_with($output, '%PDF')) {
            throw new SubmissionException('The PDF could not be prepared just now. Please try again in a moment.');
        }

        $pages = null;

        try {
            $pages = (int) $pdf->getDomPDF()->getCanvas()->get_page_count() ?: null;
        } catch (\Throwable) {
            // The count is a nicety; the PDF is fine without it.
        }

        return ['output' => $output, 'pages' => $pages];
    }
}
