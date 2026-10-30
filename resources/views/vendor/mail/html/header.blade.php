@props(['url'])
{{--
    The wordmark at the top of every CollabSpace email.

    Laravel's published version carries a branch that swaps in a logo hosted on
    laravel.com whenever the app is named "Laravel". It was dead code here —
    the name is CollabSpace — but it was also a remote image in a transactional
    email, which mail clients block and privacy-minded recipients treat as a
    tracking pixel. Text instead: it renders everywhere, needs no network, and
    matches the pill mark the app itself uses.
--}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">{!! $slot !!}</a>
</td>
</tr>
