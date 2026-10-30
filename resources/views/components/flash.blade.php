{{--
    The fallback for a password that could not be emailed.

    On the normal path this renders nothing. An issued password goes to its
    owner by email and is never put on the administrator's screen: it belongs to
    one person, and a secret on a screen is a secret in whatever is behind that
    screen — a shoulder in a lab, a projector, a screenshot pasted into a report.
    The administrator gets a plain success toast instead.

    It appears only when the send failed, because then email is not a channel
    and the administrator is the only remaining way for the password to reach
    the person who needs it. Deliberately styled as a warning rather than as
    good news, and deliberately not a toast: it must not disappear on a timer,
    because it is shown exactly once and cannot be looked up again.
--}}
@if (session('temporary_password'))
    <div class="flex flex-col gap-3 rounded-panel border border-danger-line bg-danger-bg p-5" x-data="{ copied: false }">
        <div class="flex items-start gap-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-pill bg-danger text-white">
                <x-icon name="alert" size="16" class="[stroke-width:2.5]" />
            </span>

            <div class="flex min-w-0 flex-col gap-1">
                <span class="text-label font-bold uppercase tracking-eyebrow text-danger-text">
                    Email failed — hand this over yourself
                </span>
                <p class="text-body font-medium text-danger-text">
                    {{ session('status') }}
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <code class="select-all rounded-pill bg-surface px-4 py-2.5 font-mono text-lead font-bold tracking-[.06em]"
                  x-ref="secret">{{ session('temporary_password') }}</code>

            <x-button variant="secondary"
                      x-on:click="navigator.clipboard.writeText($refs.secret.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
            </x-button>

            <span class="text-meta text-danger-text">
                For {{ session('temporary_password_for') }}. They must change it at first sign-in.
            </span>
        </div>
    </div>
@endif
