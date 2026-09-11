{{--
    Session feedback for the admin screens. A freshly issued temporary password
    is shown exactly once, here — it is never stored in plaintext, so if the
    admin navigates away it can only be reissued, not recovered.
--}}

@if (session('status') && ! session('temporary_password'))
    <div class="flex items-center gap-2.5 rounded-card bg-success-bg px-4 py-3 text-body font-semibold text-success-text">
        <x-icon name="check" size="17" />
        <span>{{ session('status') }}</span>
    </div>
@endif

@if ($errors->has('status'))
    <div class="flex items-center gap-2.5 rounded-card bg-danger-bg px-4 py-3 text-body font-semibold text-danger-text">
        <x-icon name="alert" size="17" />
        <span>{{ $errors->first('status') }}</span>
    </div>
@endif

@if (session('temporary_password'))
    <div class="flex flex-col gap-3 rounded-panel bg-periwinkle p-5" x-data="{ copied: false }">
        <div class="flex flex-col gap-1">
            <span class="text-label font-bold uppercase tracking-eyebrow text-ink/[.55]">Temporary password</span>
            <p class="text-body font-medium text-ink/[.7]">
                {{ session('status') }}
                Give this to {{ session('temporary_password_for') }} directly — it is shown once and
                cannot be looked up again. They must change it at first sign-in.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <code class="select-all rounded-pill bg-surface px-4 py-2.5 font-mono text-lead font-bold tracking-[.06em]"
                  x-ref="secret">{{ session('temporary_password') }}</code>

            <x-button variant="secondary"
                      x-on:click="navigator.clipboard.writeText($refs.secret.textContent.trim()).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
            </x-button>
        </div>
    </div>
@endif
