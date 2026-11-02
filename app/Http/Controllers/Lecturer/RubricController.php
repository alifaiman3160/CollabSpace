<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\ConfirmRubricRequest;
use App\Models\Rubric;
use App\Models\MarkSuggestion;
use App\Models\RubricSection;
use App\Models\Subject;
use App\Support\Marking\EvidenceType;
use App\Support\Formation\FormationSession;
use App\Support\Rubric\RubricException;
use App\Support\Rubric\RubricParser;
use App\Support\Rubric\RubricStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A subject's marking rubric: upload an .xlsx, review what was read from it,
 * correct it, and only then save it.
 *
 *   index     the saved versions, and the upload
 *   store     reads the file into a draft held in the session. Writes nothing.
 *   review    the draft as an editable form
 *   confirm   the one action that saves, as the next version
 *   discard   throws the draft away
 *   show      a saved version, read only
 *   destroy   removes a saved version that nothing depends on
 *
 * The draft lives in the session, bound to this lecturer and subject, through
 * the same holder the group formation screens use. What is saved is what the
 * review form posts, never what the file said.
 */
class RubricController extends LecturerController
{
    protected const KIND = 'rubric';

    public function __construct(
        protected RubricParser $parser,
        protected RubricStore $store,
        protected FormationSession $drafts,
    ) {}

    public function index(Request $request, Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);
        $assessment = $this->assessmentFor($subject, $request->query('assessment'));

        return view('lecturer.rubrics.index', [
            'subject' => $subject,
            'assessment' => $assessment,
            'assessments' => $subject->assessments,
            'rubrics' => $subject->rubrics()
                ->where('assessment_id', $assessment?->getKey())
                ->withCount(['sections', 'criteria'])
                ->with('creator')
                ->orderByDesc('version')
                ->get(),
        ]);
    }

    public function store(Request $request, Subject $subject): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
        ], [
            'file.required' => 'Choose the rubric file to upload.',
            'file.max' => 'That file is larger than 2 MB, which is more than a rubric needs.',
            'file.extensions' => 'The rubric must be an .xlsx file.',
            'file.mimetypes' => 'The rubric must be an .xlsx file.',
        ]);

        $file = $request->file('file');

        try {
            $draft = $this->parser->parse($file->getRealPath(), $file->getClientOriginalName());
        } catch (RubricException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $draft['source'] = mb_substr($file->getClientOriginalName(), 0, 255);
        // Which assessment this rubric is for: the one chosen on the page (a foreign id is ignored), else the active one.
        $draft['assessment_id'] = $this->assessmentFor($subject, $request->input('assessment'))?->getKey();

        $token = $this->drafts->put(self::KIND, $subject->id, $draft);

        return redirect()->route('lecturer.rubrics.review', [$subject, $token]);
    }

    public function review(Subject $subject, string $token): View|RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $draft = $this->drafts->get(self::KIND, $token, $subject->id);

        if ($draft === null) {
            return $this->expired($subject);
        }

        // After a refused save the form comes back as the lecturer left it.
        $sections = old('sections', $draft['sections']);

        return view('lecturer.rubrics.review', [
            'subject' => $subject,
            'token' => $token,
            'name' => old('name', $draft['name']),
            'sections' => array_values($sections),
            'warnings' => $draft['warnings'],
            'source' => $draft['source'] ?? null,
            'assessment' => $assessment = $this->assessmentFor($subject, $draft['assessment_id'] ?? null),
            'nextVersion' => ((int) $subject->rubrics()->where('assessment_id', $assessment?->getKey())->max('version')) + 1,
        ]);
    }

    public function confirm(ConfirmRubricRequest $request, Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $draft = $this->drafts->get(self::KIND, $token, $subject->id);

        if ($draft === null) {
            return $this->expired($subject);
        }

        $assessment = $this->assessmentFor($subject, $draft['assessment_id'] ?? null);

        $rubric = $this->store->create($subject, $this->lecturer(), $request->validated(), $draft['source'] ?? null, $assessment);

        $this->drafts->forget(self::KIND, $token);

        return redirect()
            ->route('lecturer.rubrics', $subject->assessments()->count() > 1 ? [$subject, 'assessment' => $rubric->assessment_id] : [$subject])
            ->with('status', "{$rubric->name} is saved as version {$rubric->version} for {$subject->code}.");
    }

    public function discard(Subject $subject, string $token): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $this->drafts->forget(self::KIND, $token);

        return redirect()
            ->route('lecturer.rubrics', $subject)
            ->with('status', 'The uploaded rubric was discarded. Nothing was saved.');
    }

    public function show(Subject $subject, Rubric $rubric): View
    {
        $rubric = $this->ownedRubric($subject, $rubric);

        $rubric->load(['sections.criteria.levels', 'creator']);

        return view('lecturer.rubrics.show', ['subject' => $subject, 'rubric' => $rubric]);
    }

    /**
     * Sets, on a saved rubric version, who decides each criterion (the system may
     * suggest, or the lecturer alone) and what recorded activity a System-suggested
     * criterion looks at.
     *
     * These are settings for suggestions, not rubric content: no level, weight or
     * descriptor moves. Even so, once any student's marks against this version
     * are approved they rest on it as it stands, so on a locked version every
     * change is refused; the way to change them then is a new version.
     */
    public function updateEvidence(Request $request, Subject $subject, Rubric $rubric): RedirectResponse
    {
        $rubric = $this->ownedRubric($subject, $rubric);

        if ($rubric->isLocked()) {
            return redirect()
                ->route('lecturer.rubrics.show', [$subject, $rubric])
                ->withErrors(['status' => 'Some marks are already approved, so who decides each criterion, and what its suggestion is based on, can no longer be changed. Upload the rubric again as a new version instead.']);
        }

        $request->validate([
            'evidence' => ['nullable', 'array'],
            'evidence.*' => ['nullable', Rule::in(EvidenceType::ALL)],
            'mode' => ['nullable', 'array'],
            'mode.*' => ['nullable', Rule::in([RubricSection::SUGGESTED, RubricSection::MANUAL])],
        ], [
            'evidence.*.in' => 'Choose one of the options listed.',
            'mode.*.in' => 'Choose System-suggested or Manual.',
        ]);

        $evidence = (array) $request->input('evidence', []);
        $modes = (array) $request->input('mode', []);

        $criteria = $rubric->criteria()->with('section')->get();

        // A criterion can only become System-suggested in a section whose marks are
        // worked out, because a suggestion is worth nothing without a formula.
        foreach ($criteria as $criterion) {
            $new = $modes[$criterion->id] ?? $criterion->mode;

            if ($new === RubricSection::SUGGESTED && $criterion->mode !== RubricSection::SUGGESTED && $criterion->section->scoring === null) {
                return redirect()
                    ->route('lecturer.rubrics.show', [$subject, $rubric])
                    ->withErrors(['status' => "“{$criterion->name}” cannot be System-suggested: its section has no scoring formula chosen. Upload a new version with one."]);
            }
        }

        DB::transaction(function () use ($criteria, $evidence, $modes) {
            foreach ($criteria as $criterion) {
                $mode = $modes[$criterion->id] ?? $criterion->mode;
                $type = $mode === RubricSection::SUGGESTED && array_key_exists($criterion->id, $evidence) && filled($evidence[$criterion->id])
                    ? $evidence[$criterion->id]
                    : ($mode === RubricSection::SUGGESTED && ! array_key_exists($criterion->id, $evidence) ? $criterion->evidence_type : null);

                if ($mode !== $criterion->mode) {
                    // Suggestions made for the old mode no longer describe this criterion.
                    MarkSuggestion::where('rubric_criterion_id', $criterion->id)->delete();
                }

                $criterion->update(['mode' => $mode, 'evidence_type' => $type]);
            }
        });

        return redirect()
            ->route('lecturer.rubrics.show', [$subject, $rubric])
            ->with('status', 'Criterion settings saved.');
    }
    public function destroy(Subject $subject, Rubric $rubric): RedirectResponse
    {
        $rubric = $this->ownedRubric($subject, $rubric);

        if ($rubric->isLocked()) {
            return redirect()
                ->route('lecturer.rubrics', $subject)
                ->withErrors(['status' => 'This version has approved marks against it, so it cannot be deleted. Upload a new version instead.']);
        }

        $label = "Version {$rubric->version}";
        $wasCurrent = $rubric->is_current;

        $rubric->delete();

        // Deleting the newest version hands "current" back to the one before it.
        if ($wasCurrent) {
            $subject->rubrics()->where('assessment_id', $rubric->assessment_id)->orderByDesc('version')->first()?->update(['is_current' => true]);
        }

        return redirect()
            ->route('lecturer.rubrics', $subject)
            ->with('status', "{$label} was deleted.");
    }

    /**
     * The assessment a page is about: the one asked for when it is one of this subject's (anything else is ignored, so
     * another subject's or lecturer's id leaks nothing), otherwise the subject's active one. Null when it has none.
     */
    protected function assessmentFor(Subject $subject, mixed $id): ?\App\Models\Assessment
    {
        return (filled($id) ? $subject->assessments->firstWhere('id', (int) $id) : null) ?? $subject->activeAssessment();
    }

    /** A rubric that belongs to this subject, which belongs to this lecturer. */
    protected function ownedRubric(Subject $subject, Rubric $rubric): Rubric
    {
        $this->ownedSubject($subject);

        abort_unless($rubric->subject_id === $subject->id, 404);

        return $rubric;
    }

    protected function expired(Subject $subject): RedirectResponse
    {
        return redirect()
            ->route('lecturer.rubrics', $subject)
            ->withErrors(['status' => 'That upload has expired. Upload the file again.']);
    }
}
