<?php

namespace App\Livewire;

use App\Models\Group;
use App\Models\Message;
use App\Support\ActivityRecorder;
use App\Support\GroupFiles;
use App\Support\Notifier;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Group chat.
 *
 * Near-real-time by polling rather than websockets: wire:poll.5s in the view.
 * That needs no broadcasting server, no queue worker and no third-party
 * service, at the cost of up to five seconds' latency.
 *
 * Membership is re-checked on every action rather than only at mount, because
 * Livewire round-trips the component's state back from the browser.
 */
class GroupChat extends Component
{
    use WithFileUploads;

    /** Extensions the upload field accepts. Documented in the view too. */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'zip'];

    /**
     * Real MIME types, checked against the file's actual contents rather than
     * its name. The OOXML formats (docx/xlsx/pptx) are zip containers and are
     * frequently sniffed as application/zip, so that is allowed here and the
     * extension rule below is what separates them from a plain archive.
     */
    public const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/png',
        'image/jpeg',
        'application/zip',
        'application/x-zip-compressed',
        // Legacy Office containers sniff as this on some systems.
        'application/vnd.ms-office',
        // Deliberately NOT application/octet-stream: that is the fallback for
        // anything unrecognised, so allowing it would let every file type
        // through and make this check meaningless.
    ];

    /**
     * A small curated set for the picker, grouped by row.
     *
     * Deliberately hand-picked rather than pulled from an emoji library: a few
     * dozen characters covers what a project group actually uses, and the
     * alternative is hundreds of kilobytes of data and images for a feature
     * that is a convenience. The textarea accepts any emoji pasted or typed
     * from the OS picker regardless.
     */
    public const EMOJI = [
        ['👍', '👏', '🙌', '🙏', '💪', '🤝', '👀', '✅'],
        ['😀', '😄', '😅', '😂', '🙂', '😉', '😍', '🤔'],
        ['😴', '😭', '😱', '😤', '🥲', '🤯', '🫠', '💀'],
        ['🔥', '🎉', '⭐', '❤️', '⚡', '💡', '📌', '🚀'],
        ['📄', '📊', '🗓️', '⏰', '⚠️', '❌', '❓', '☕'],
    ];

    /** 10 MB, expressed in kilobytes for the validator. */
    public const MAX_KILOBYTES = 10 * 1024;

    public Group $group;

    /** True when a switch asked for a group this student is not in: the pane says so instead of breaking. */
    public bool $missing = false;

    public string $body = '';

    public $attachment = null;

    public function mount(Group $group): void
    {
        $this->group = $this->authorizedGroup($group);
    }

    /**
     * Opens another of the student's groups in place. The page's list calls this instead of reloading.
     * Membership is checked here exactly as on a full page visit; a group that is not theirs does not
     * load, and the pane shows a short message (the full-page route still answers 404).
     */
    #[On('open-group')]
    public function openGroup(int $id): void
    {
        $group = Group::query()->with('subject')->find($id);

        $allowed = $group !== null
            && auth()->check()
            && auth()->user()->groupMemberships()->where('group_id', $id)->exists();

        if (! $allowed) {
            $this->missing = true;
            $this->dispatch('chat-missing');

            return;
        }

        $this->missing = false;
        $this->group = $group;
        $this->reset(['body', 'attachment']);
        $this->resetValidation();

        $this->dispatch('chat-opened', id: $group->id, name: $group->name);
    }

    protected function authorizedGroup(Group $group): Group
    {
        abort_unless(
            auth()->check()
                && auth()->user()->groupMemberships()->where('group_id', $group->getKey())->exists(),
            404,
        );

        return $group;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000'],
            'attachment' => [
                'nullable',
                'file',
                'max:'.self::MAX_KILOBYTES,
                // Contents first, then filename — both must agree.
                'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES),
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'attachment.max' => 'Files are capped at 10 MB.',
            'attachment.mimetypes' => 'That file type is not allowed. Accepted: '.$this->allowedLabel().'.',
            'attachment.extensions' => 'That file type is not allowed. Accepted: '.$this->allowedLabel().'.',
        ];
    }

    public function allowedLabel(): string
    {
        return 'PDF, Word, Excel, PowerPoint, PNG, JPG, ZIP';
    }

    public function send(): void
    {
        $this->authorizedGroup($this->group);

        $this->validate();

        $body = trim($this->body);

        // A message has to carry something.
        if ($body === '' && $this->attachment === null) {
            $this->addError('body', 'Write a message or attach a file.');

            return;
        }

        $stored = null;
        $originalName = null;
        $size = null;

        if ($this->attachment !== null) {
            // The stored name is random and never derived from what the sender
            // called the file — an uploaded name is attacker-controlled and has
            // no business deciding a path on disk. The original is kept in the
            // database purely for display.
            $originalName = $this->attachment->getClientOriginalName();
            $extension = strtolower($this->attachment->getClientOriginalExtension());
            $size = $this->attachment->getSize();

            $stored = $this->attachment->storeAs(
                // The same helper the group-deletion cleanup reads, so the path
                // written here can never drift from the path that is purged.
                GroupFiles::directoryFor($this->group),
                Str::random(40).'.'.$extension,
                GroupFiles::DISK,   // storage/app/private — never web-served
            );
        }

        $message = Message::create([
            'group_id' => $this->group->id,
            'user_id' => auth()->id(),
            'body' => $body !== '' ? $body : null,
            'file_path' => $stored,
            'file_name' => $originalName,
            'file_size' => $size,
        ]);

        $recorder = app(ActivityRecorder::class);

        // Text and file are separate contributions; a message with both writes
        // both rows. messageSent() applies the daily cap on its own.
        if ($stored !== null) {
            $recorder->fileUploaded($message);
        }

        if ($body !== '') {
            $recorder->messageSent($message);
        }

        app(Notifier::class)->messagePosted($message, auth()->user());

        $this->reset(['body', 'attachment']);
        $this->resetValidation();

        $this->dispatch('chat-message-sent');
    }

    public function removeAttachment(): void
    {
        $this->reset('attachment');
        $this->resetValidation('attachment');
    }

    public function render(): View
    {
        $messages = Message::query()
            ->where('group_id', $this->group->id)
            ->with('user')
            ->oldest()
            ->get();

        $this->group->loadMissing('subject');

        return view('livewire.group-chat', [
            'memberCount' => $this->group->groupMembers()->count(),
            // Grouped by calendar day so the view can render a date divider.
            'days' => $messages->groupBy(fn (Message $m) => $m->created_at->toDateString()),
            'emoji' => self::EMOJI,
        ]);
    }
}
