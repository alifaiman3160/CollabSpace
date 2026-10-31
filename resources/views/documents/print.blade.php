<!DOCTYPE html>
{{--
    The print layout for a document: A4, 2.5 cm margins, 12 pt text, the title first, then the body, with
    the page number centred in the footer. Plain block CSS on purpose (no flex, no grid), so it works for
    a browser's Print and for an HTML-to-PDF step alike. The body arrives already prepared by
    App\Support\DocumentPrintBody: page breaks are real, and the contents are expanded.

    Nothing here names a student or a group: a report should read clean.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('documents.print-styles')
</head>
<body>
    <div class="page-footer"></div>

    <h1 class="doc-title">{{ $title }}</h1>

    {!! $body !!}
</body>
</html>
