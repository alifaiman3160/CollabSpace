<x-mail::message>
# Your account is ready

Hello {{ $user->name }},

@if ($createdBy)
{{ $createdBy }} has created a {{ config('app.name') }} account for you.
@else
An administrator has created a {{ config('app.name') }} account for you.
@endif
{{ config('app.name') }} is where your group coursework lives — the shared task
board, documents, group chat, and the contribution record your lecturer sees.

**Your sign-in details**

- **Email:** {{ $user->email }}
- **Temporary password:** {{ $temporaryPassword }}
- **Role:** {{ $roleLabel }}
@if ($user->matric_no)
- **Matriculation number:** {{ $user->matric_no }}
@endif

<x-mail::button :url="$loginUrl">
Sign in to {{ config('app.name') }}
</x-mail::button>

## What happens first

The password above is temporary. The first time you sign in, {{ config('app.name') }}
will ask you to replace it before you can reach anything else — so please sign in
soon and choose your own.

@if ($user->role === 'student')
You will not see a task board straight away. Your lecturer assigns you to a group
for each subject, and your workspace appears once that happens. There is nothing
you need to do about that.
@endif

If you were not expecting this email, please tell your faculty administrator —
do not sign in.

Thanks,<br>
{{ config('app.name') }}

<x-mail::subcopy>
If the button above does not work, paste this address into your browser:
[{{ $loginUrl }}]({{ $loginUrl }})
</x-mail::subcopy>
</x-mail::message>
