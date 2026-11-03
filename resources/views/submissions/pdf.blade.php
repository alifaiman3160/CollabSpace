<!DOCTYPE html>
{{--
    A submission's PDF: a cover page, then each chosen document starting on a new page. The same print styles as a single
    document (plain block CSS, continuous page numbers in the footer).
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $group->subject->code }} {{ $assessment->title }} - {{ $group->name }}</title>
    @include('documents.print-styles')
    <style>
        .cover-code { font-size: 12pt; color: #4d4d57; margin: 0 0 4pt; }
        .cover-title { font-size: 26pt; line-height: 1.2; font-weight: bold; margin: 0 0 6pt; }
        .cover-sub { font-size: 16pt; margin: 0 0 22pt; }
        .cover-table { margin: 0 0 14pt; }
        .cover-row { margin: 0; padding: 6pt 4pt; font-size: 11pt; border-bottom: 1pt solid #d6d6e0; }
        .cover-row .k { display: inline-block; width: 28%; color: #4d4d57; }
        .cover-flag { font-weight: bold; }
        .doc-section { page-break-before: always; }
    </style>
</head>
<body>
    <div class="page-footer"></div>

    <p class="cover-code">{{ $group->subject->code }} - {{ $group->subject->name }}</p>
    <h1 class="cover-title">{{ $assessment->title }}</h1>
    <p class="cover-sub">{{ $group->name }}</p>

    <div class="cover-table">
        <p class="cover-row"><span class="k">Subject</span> <span>{{ $group->subject->code }} - {{ $group->subject->name }}</span></p>
        <p class="cover-row"><span class="k">Assessment</span> <span>{{ $assessment->title }}</span></p>
        <p class="cover-row"><span class="k">Group</span> <span>{{ $group->name }}</span></p>
        @if ($group->section)
            <p class="cover-row"><span class="k">Section</span> <span>{{ $group->section->name }}</span></p>
        @endif
        <p class="cover-row"><span class="k">Members</span> <span>{{ $group->members->sortBy('name')->pluck('name')->implode(', ') }}</span></p>
        <p class="cover-row"><span class="k">Submitted by</span> <span>{{ $by->name }}</span></p>
        <p class="cover-row"><span class="k">Submitted</span> <span>{{ $at->format('j F Y, g:i a') }} (Asia/Kuala_Lumpur)</span></p>
        <p class="cover-row"><span class="k">Status</span> <span class="cover-flag">{{ $late ? 'Late' : 'On time' }}</span></p>
        <p class="cover-row"><span class="k">Documents</span> <span>{{ collect($sections)->pluck('title')->implode('; ') }}</span></p>
    </div>

    @foreach ($sections as $section)
        <div class="doc-section">
            <h1 class="doc-title">{{ $section['title'] }}</h1>
            {!! $section['body'] !!}
        </div>
    @endforeach
</body>
</html>
