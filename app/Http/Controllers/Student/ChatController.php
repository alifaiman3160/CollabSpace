<?php

namespace App\Http\Controllers\Student;

use App\Models\Group;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends StudentController
{
    /** Parameterless entry point, so the sidebar can link without a group id. */
    public function index(): View|RedirectResponse
    {
        $groups = $this->myGroups();

        if ($groups->isEmpty()) {
            return view('student.chat', ['hasGroup' => false, 'group' => null, 'groups' => $groups]);
        }

        return redirect()->route('student.chat.show', $groups->first());
    }

    public function show(Group $group): View
    {
        $group = $this->ownedGroup($group);

        $groups = $this->myGroups();
        $groups->loadCount('groupMembers as members_count');

        return view('student.chat', [
            'hasGroup' => true,
            'group' => $groups->firstWhere('id', $group->id) ?? $group->load('subject'),
            'groups' => $groups,
            // The newest message of each of this student's groups, for the list on the left.
            'lastMessages' => Message::query()
                ->whereIn('id', Message::query()->selectRaw('max(id)')->whereIn('group_id', $groups->modelKeys())->groupBy('group_id'))
                ->with('user:id,name')
                ->get()
                ->keyBy('group_id'),
        ]);
    }

    /**
     * Serves a chat attachment.
     *
     * Uploads live in storage/app/private, which is never web-served, so this
     * is the only route to them. Two things have to line up: the caller must be
     * a member of the message's group, and the URL must carry the random token
     * from the stored filename — so the URL cannot be guessed from a message id
     * and cannot be used by anyone outside the group even if it leaks.
     *
     * Sent as a download by default. Only png and jpeg may be requested inline
     * (?inline=1), so the thread can show a thumbnail and a lightbox — those
     * two types cannot be coaxed into executing. Everything else stays an
     * attachment, so nothing a browser might run can render on this origin.
     */
    public function file(Request $request, Message $message, string $token): StreamedResponse
    {
        abort_unless($message->hasAttachment(), 404);

        // Membership first — this is the authorisation check.
        $this->ownedGroup($message->group);

        // Then the token, compared without leaking timing.
        abort_unless(
            $message->fileToken() !== null && hash_equals($message->fileToken(), $token),
            404,
        );

        $disk = Storage::disk('local');

        // Seeded demo rows reference files that were never written to disk.
        abort_unless($disk->exists($message->file_path), 404);

        // Images may be served inline so the thread can show a thumbnail and
        // a lightbox. Restricted to png/jpeg, which browsers cannot be tricked
        // into executing — serving arbitrary uploads inline would hand an
        // attacker script execution on this origin.
        if ($request->boolean('inline') && $this->isInlineSafeImage($message)) {
            return $disk->response($message->file_path, $message->file_name, [
                'Content-Type' => $this->imageMimeFor($message),
                'Content-Disposition' => 'inline; filename="'.addslashes($message->file_name ?? 'image').'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return $disk->download(
            $message->file_path,
            $message->file_name ?? 'attachment',
            ['Content-Type' => 'application/octet-stream'],
        );
    }

    /** png and jpeg only — nothing a browser will execute. */
    protected function isInlineSafeImage(Message $message): bool
    {
        return in_array($this->extensionOf($message), ['png', 'jpg', 'jpeg'], true);
    }

    protected function imageMimeFor(Message $message): string
    {
        return $this->extensionOf($message) === 'png' ? 'image/png' : 'image/jpeg';
    }

    /** Taken from the stored path, which this app generated — not from the
     *  uploader-supplied display name. */
    protected function extensionOf(Message $message): string
    {
        return strtolower(pathinfo((string) $message->file_path, PATHINFO_EXTENSION));
    }
}
