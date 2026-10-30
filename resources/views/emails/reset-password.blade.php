<x-mail::message>
# Reset your password

Hello {{ $user->name }},

Someone asked to reset the password on your {{ config('app.name') }} account
({{ $user->email }}). If that was you, use the button below to choose a new one.

<x-mail::button :url="$resetUrl">
Choose a new password
</x-mail::button>

This link expires in {{ $expiresInMinutes }} minutes and works once. After that,
ask for another from the sign-in screen.

**If it was not you**, nothing has changed and you do not need to do anything —
your current password still works and nobody can use this link without opening
this email. If these keep arriving, tell your faculty administrator.

Thanks,<br>
{{ config('app.name') }}

<x-mail::subcopy>
If the button above does not work, paste this address into your browser:
[{{ $resetUrl }}]({{ $resetUrl }})
</x-mail::subcopy>
</x-mail::message>
