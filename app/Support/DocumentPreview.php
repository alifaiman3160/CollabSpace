<?php

namespace App\Support;

use App\Models\Document;

/**
 * What a document card on the Documents list shows: the first page of the latest saved version, small.
 *
 * Cheap on purpose. The list query hands over only the head of each body (see DocumentController::group), this cuts it
 * at the first page-break marker, keeps at most LIMIT characters, and runs it through the same sanitiser as everything
 * else, so no script, event attribute or foreign image can appear. Pictures are only ever this app's own (the
 * member-checked image route) and load lazily; links are made plain text because the whole card is a link.
 */
class DocumentPreview
{
    /** Characters of the first page kept for the preview. */
    public const LIMIT = 1500;

    /** Characters of each body the list query reads: the preview never needs more. */
    public const HEAD = 6000;

    public function __construct(protected DocumentSanitizer $sanitizer) {}

    /** The page-break marker, in every spelling the sanitiser stores. */
    public const MARKER = '#<p\b[^>]*\bdata-page-break\b[^>]*>(?:\s|<br\s*/?>)*</p>#i';

    /** @return array{html: string, blank: bool, pages: int} */
    public function for(Document $document): array
    {
        $head = (string) ($document->content_head ?? '');
        $first = preg_split(self::MARKER, $head, 2)[0] ?? '';
        $first = mb_substr($first, 0, self::LIMIT);

        $html = trim($this->sanitizer->render($first));

        // The card is one link, and a link inside a link is not allowed.
        $html = preg_replace('#<a\b[^>]*>(.*?)</a>#is', '<span>$1</span>', $html) ?? $html;
        $html = str_replace('<img ', '<img loading="lazy" decoding="async" ', $html);

        $blank = trim(strip_tags($html)) === '' && ! str_contains($html, '<img') && ! str_contains($html, '<table');

        return [
            'html' => $blank ? '' : $html,
            'blank' => $blank,
            // From the markers in the saved body (one query counted them), plus the page they end.
            'pages' => max(1, (int) ($document->page_breaks ?? 0) + 1),
        ];
    }
}