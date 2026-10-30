<x-mail::message>
# Your password has been reset

Hello {{ $user->name }},

@if ($issuedBy)
{{ $issuedBy }} has issued you a new temporary password for {{ config('app.name') }}.
@else
An administrator has issued you a new temporary password for {{ config('app.name') }}.
@endif
Your previous password stopped working the moment this was done.

**Your temporary password**

- **Email:** {{ $user->email }}
- **Temporary password:** {{ $temporaryPassword }}

<x-mail::button :url="$loginUrl">
Sign in to {{ config('app.name') }}
</x-mail::button>

The password above is temporary. {{ config('app.name') }} will ask you to replace
it the first time you sign in with it, before you can reach anything else, so
please do that soon and choose your own.

**If you did not ask for this**, tell your faculty administrator now — somebody
has reset your account without your knowledge.

Thanks,<br>
{{ config('app.name') }}

<x-mail::subcopy>
If the button above does not work, paste this address into your browser:
[{{ $loginUrl }}]({{ $loginUrl }})
</x-mail::subcopy>
</x-mail::message>
