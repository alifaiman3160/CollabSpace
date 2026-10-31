<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\MarkSuggestion;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Support\Marking\EvidenceType;
use App\Support\Marking\GroupFacts;
use App\Support\Marking\SuggestionService;
use Illuminate\Console\Command;

/**
 * Runs the evidence-based suggestions for one group against one rubric and
 * prints what came out, so the whole pipeline can be checked before there is a
 * marking screen to look at it in.
 *
 * Per student and per system-suggested criterion: the facts, the rule-based
 * baseline, the AI's suggestion (or why there is none), the marks each would be
 * worth, and any flag. Results are stored exactly as a real run would store
 * them. Pass --no-ai to skip the provider and see the baseline alone.
 *
 * Prints no API key and nothing of the provider's configuration beyond its name
 * and model. The data sent to the provider has no names, emails, matric numbers
 * or CGPA, but this output does include student names, because it is for the
 * lecturer at the terminal.
 */
class PreviewMarkingSuggestions extends Command
{
    protected $signature = 'marking:preview {rubric : Rubric id} {group : Group id} {--no-ai : Show the rule-based baseline only}';

    protected $description = 'Show evidence-based mark suggestions for a group, without the marking screens';

    public function handle(SuggestionService $service, GroupFacts $facts): int
    {
        $rubric = Rubric::with(['subject.lecturer', 'sections.criteria.levels'])->find($this->argument('rubric'));
        $group = Group::with('members')->find($this->argument('group'));

        if (! $rubric || ! $group) {
            $this->error('There is no '.(! $rubric ? 'rubric' : 'group').' with that id.');

            return self::FAILURE;
        }

        if ($group->subject_id !== $rubric->subject_id) {
            $this->error('That group does not belong to the subject this rubric is for.');

            return self::FAILURE;
        }

        $lecturer = $rubric->subject->lecturer;

        $this->line("Rubric   {$rubric->name} (version {$rubric->version}) for {$rubric->subject->code}");
        $this->line("Group    {$group->name}, {$group->members->count()} members");

        $report = $service->run($lecturer, $rubric, $group, ! $this->option('no-ai'));

        $this->line('Mode     '.($this->option('no-ai') ? 'rule-based only' : 'rule-based and AI'));
        $this->newLine();

        $criteria = $rubric->sections->flatMap->criteria->filter->isSuggested()->values();

        if ($criteria->isEmpty()) {
            $this->warn('This rubric has no System-suggested criteria, so there is nothing to suggest.');

            return self::SUCCESS;
        }

        $stored = MarkSuggestion::query()
            ->where('group_id', $group->id)
            ->whereIn('rubric_criterion_id', $criteria->pluck('id'))
            ->get()
            ->groupBy('student_id');

        foreach ($group->members->sortBy('name') as $student) {
            $this->info($student->name);
            $own = $stored->get($student->id, collect());

            $this->line('  '.$this->factsLine($own->first()?->facts ?? []));

            foreach ($criteria as $criterion) {
                $this->criterion($criterion, $own->firstWhere('rubric_criterion_id', $criterion->id));
            }

            $this->totals($rubric, $own);

            $this->newLine();
        }

        $this->summary($report);

        return self::SUCCESS;
    }

    protected function criterion(RubricCriterion $criterion, ?MarkSuggestion $s): void
    {
        $this->line("  <options=bold>{$criterion->name}</>  [".EvidenceType::label($criterion->evidence_type).']');

        if ($s === null) {
            $this->line('    no suggestion stored');

            return;
        }

        if ($s->insufficient_data) {
            // No level, from the baseline or the AI: the group has done too little to compare students.
            $this->line('    <fg=yellow>insufficient data:</> '.$s->needs_input_reason);

            return;
        }

        if ($s->needs_input) {
            $this->line('    Needs lecturer input. '.$s->needs_input_reason);

            return;
        }

        $this->line('    From the numbers: '.$this->levelLine($criterion, $s->baseline_level_id).' | '.$s->baseline_note);

        if ($s->ai_level_id !== null) {
            $this->line('    AI\'s view: '.$this->levelLine($criterion, $s->ai_level_id).' | '.$s->ai_rationale);
        } else {
            $this->line('    AI\'s view: unavailable. '.($s->ai_message ?? ($s->ai_status === 'not_asked' ? 'Not requested.' : '')));
        }

        if ($s->differs) {
            $this->warn('    Flag: the baseline and the AI suggestion differ by more than one level.');
        }
    }

    /**
     * Section totals as the suggestions stand. A criterion with no level, whether
     * it is the lecturer's own or the group has recorded too little, is undecided
     * and not zero, so a section with any of those has no total yet.
     */
    protected function totals(Rubric $rubric, \Illuminate\Support\Collection $own): void
    {
        foreach ($rubric->sections as $section) {
            if (! $section->criteria->contains(fn ($c) => $c->isSuggested())) {
                continue;
            }

            $t = app(\App\Support\Marking\SectionTotals::class)->for($section, $own);
            $max = $t['maximum'] === null ? '' : ' of '.\App\Support\Rubric\RubricScoring::trim($t['maximum']);

            if (! $t['formula_chosen']) {
                $this->line("  Total, {$section->title}: incomplete. The scoring formula is not chosen.");
            } elseif ($t['complete']) {
                $this->line("  Total, {$section->title}: ".\App\Support\Rubric\RubricScoring::trim($t['total'])."{$max} marks if these suggestions are taken");
            } else {
                $this->line("  Total, {$section->title}: <fg=yellow>incomplete</>, ".\App\Support\Rubric\RubricScoring::trim($t['so_far'])."{$max} marks so far, {$t['undecided']} of {$t['criteria']} ".($t['undecided'] === 1 ? 'criterion' : 'criteria').' undecided until you choose a level');
            }
        }
    }

    /** "Good (3 of 4 levels, 1.5 marks)": the marks come from RubricScoring, never from a model. */
    protected function levelLine(RubricCriterion $criterion, ?int $levelId): string
    {
        $levels = $criterion->levels->sortBy([['marks', 'asc'], ['position', 'asc']])->values();
        $index = $levels->search(fn ($l) => $l->id === $levelId);

        if ($index === false) {
            return '—';
        }

        $level = $levels[$index];
        $section = $criterion->section;

        $marks = $section->scoring === null
            ? 'formula not chosen'
            : ($m = \App\Support\Rubric\RubricScoring::trim($section->marksFor($criterion, $level))).($m === '1' ? ' mark' : ' marks');

        return "{$level->label} (level ".($index + 1).' of '.$levels->count().", {$marks})";
    }

    protected function factsLine(array $f): string
    {
        if ($f === []) {
            return 'no facts stored';
        }

        $avg = $f['group_average'] ?? [];

        return sprintf(
            'Tasks assigned %s (avg %s), completed %s, on time %s, late %s%s, overdue now %s | messages %s (avg %s) | document edits %s (avg %s) | files %s (avg %s)',
            $f['tasks_assigned'], $avg['tasks_assigned'] ?? '-',
            $f['tasks_completed'], $f['tasks_completed_on_time'],
            $f['tasks_completed_late'], ($f['late_days'] ?? []) ? ' ('.implode(', ', array_map(fn ($d) => $d.'d', $f['late_days'])).')' : '',
            $f['tasks_overdue'],
            $f['messages_sent'], $avg['messages_sent'] ?? '-',
            $f['document_edits'], $avg['document_edits'] ?? '-',
            $f['files_uploaded'], $avg['files_uploaded'] ?? '-',
        );
    }

    protected function summary(\App\Support\Marking\SuggestionReport $r): void
    {
        $this->line("Students {$r->students} | requests {$r->requests} | from AI {$r->fromAi} | rule-based {$r->fromBaseline} | discarded {$r->discarded} | needs lecturer input {$r->needsInput} | insufficient data {$r->insufficient} | flagged {$r->flagged}");

        if ($r->stopped) {
            $this->warn($r->stopped);
        }
    }
}
