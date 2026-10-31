<?php

namespace App\Http\Controllers\Lecturer;

use App\Http\Requests\Lecturer\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use App\Models\GroupMember;
use App\Models\User;
use App\Models\AppNotification;
use App\Models\Subject;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * A lecturer's announcements for one of their own subjects. Posting tells each
 * active student once; editing does not tell anyone again; deleting takes the
 * notifications away with it.
 */
class AnnouncementController extends LecturerController
{
    public function index(Subject $subject): View
    {
        $subject = $this->ownedSubject($subject);

        return $this->page($subject, null);
    }

    public function edit(Subject $subject, Announcement $announcement): View
    {
        $subject = $this->ownedSubject($subject);
        $this->belongsTo($subject, $announcement);

        return $this->page($subject, $announcement);
    }

    public function store(AnnouncementRequest $request, Subject $subject, Notifier $notifier): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);

        $announcement = $subject->announcements()->create($request->validated() + ['author_id' => $this->lecturer()->id]);

        $told = $notifier->announcementPosted($announcement);

        return redirect()->route('lecturer.announcements', $subject)->with(
            'status',
            $told === 0
                ? 'Announcement posted. No students are in this subject yet, so nobody was notified.'
                : "Announcement posted. {$told} ".str('student')->plural($told).' notified.',
        );
    }

    public function update(AnnouncementRequest $request, Subject $subject, Announcement $announcement): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        $this->belongsTo($subject, $announcement);

        $announcement->update($request->validated());

        return redirect()->route('lecturer.announcements', $subject)->with('status', 'Announcement updated. Students were not notified again.');
    }

    public function destroy(Subject $subject, Announcement $announcement): RedirectResponse
    {
        $subject = $this->ownedSubject($subject);
        $this->belongsTo($subject, $announcement);

        AppNotification::about(Announcement::TYPE, $announcement->id)->delete();
        $announcement->delete();

        return redirect()->route('lecturer.announcements', $subject)->with('status', 'Announcement deleted.');
    }

    /**
     * The page, with "Opened by X of Y" for each announcement from one grouped count.
     * Y is the active students now in the subject's groups; X counts only those same
     * students, so a deactivated account never makes X bigger than Y.
     */
    protected function page(Subject $subject, ?Announcement $editing): View
    {
        $announcements = $subject->announcements()->latest()->latest('id')->get();

        $students = GroupMember::query()
            ->whereIn('group_id', $subject->groups()->select('id'))
            ->whereIn('user_id', User::query()->where('is_active', true)->select('id'))
            ->select('user_id');

        return view('lecturer.announcements.index', [
            'subject' => $subject,
            'announcements' => $announcements,
            'editing' => $editing,
            'audience' => (clone $students)->distinct()->count('user_id'),
            'opened' => AnnouncementRead::query()
                ->whereIn('announcement_id', $announcements->modelKeys())
                ->whereIn('user_id', $students)
                ->selectRaw('announcement_id, count(distinct user_id) as total')
                ->groupBy('announcement_id')
                ->pluck('total', 'announcement_id'),
        ]);
    }

    /** An announcement reached through another subject's URL is simply not found. */
    protected function belongsTo(Subject $subject, Announcement $announcement): void
    {
        abort_unless($announcement->subject_id === $subject->id, 404);
    }
}
