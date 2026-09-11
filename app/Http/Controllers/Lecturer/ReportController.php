<?php

namespace App\Http\Controllers\Lecturer;

use App\Models\Group;
use App\Support\ContributionReport;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends LecturerController
{
    public function __construct(protected ContributionReport $reports) {}

    /** Group selection. */
    public function index(): View
    {
        $groups = $this->groups()
            // Eager-loaded so building each summary below does not re-query.
            ->with(['subject.lecturer', 'members'])
            ->withCount('groupMembers as members_count')
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        // Enough of each report to show the flag count on the selection card,
        // without building every report in full.
        $flagCounts = $groups->mapWithKeys(function (Group $group) {
            $report = $this->reports->for($group);

            return [$group->id => [
                'flagged' => $report['flagged']->count(),
                'total' => $report['total'],
            ]];
        });

        return view('lecturer.reports.index', [
            'groups' => $groups,
            'summaries' => $flagCounts,
        ]);
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        return view('lecturer.reports.show', [
            'report' => $this->reports->for($group),
            'groups' => $this->groups()->with('subject')->orderBy('name')->get(),
        ]);
    }

    /**
     * CSV rather than PDF: a PDF renderer is a heavy dependency for a table,
     * and a spreadsheet is what a lecturer actually wants to keep alongside
     * their marks.
     */
    public function export(Group $group): StreamedResponse
    {
        $group = $this->ownedGroup($group);

        $report = $this->reports->for($group);
        $rows = $this->reports->toCsvRows($report);

        $filename = str($group->subject->code.' '.$group->name)->slug()->value().'-contribution.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            // BOM so Excel opens UTF-8 names correctly rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
