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
    public function index(\Illuminate\Http\Request $request): View
    {
        $filter = \App\Support\Sections\SubjectSectionFilter::for($request, $this->lecturer());
        $groups = $this->groups()
            ->when($filter['subjectId'], fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filter['sectionId'], fn ($q, $id) => $q->where('section_id', $id))
            // Eager-loaded so building each summary below does not re-query.
            ->with(['subject.lecturer', 'section', 'members'])
            ->withCount('groupMembers as members_count')
            ->orderBy('subject_id')
            ->orderBy('name')
            ->get();

        // One read of the log for every group, then each group's own report numbers: the flag count, the total, and
        // each member's points (what the distribution bar is made of).
        $reports = $this->reports->forMany($groups);

        $summaries = $groups->mapWithKeys(function (Group $group) use ($reports) {
            $report = $reports[$group->id];

            return [$group->id => [
                'flagged' => $report['flagged']->count(),
                'total' => $report['total'],
                'segments' => $report['members']
                    ->filter(fn ($row) => $row['score'] > 0)
                    ->map(fn ($row) => ['name' => $row['user']->name, 'points' => (int) $row['score'], 'flagged' => (bool) $row['flagged']])
                    ->values()
                    ->all(),
            ]];
        });

        // Groups with flagged members first (the most flags first), then by subject and group name.
        $groups = $groups->sortBy([
            fn ($a, $b) => $summaries[$b->id]['flagged'] <=> $summaries[$a->id]['flagged'],
            fn ($a, $b) => strcmp($a->subject->code, $b->subject->code),
            fn ($a, $b) => strcmp($a->name, $b->name),
        ])->values();

        return view('lecturer.reports.index', [
            'groups' => $groups,
            'summaries' => $summaries,
            'filter' => $filter,
        ]);
    }
    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group)->load('section');

        return view('lecturer.reports.show', [
            'report' => $this->reports->for($group),
            'groups' => $this->groups()->with(['subject', 'section'])->orderBy('name')->get(),
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
