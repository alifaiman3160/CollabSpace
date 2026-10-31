<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;

/**
 * A document's headings, in the order they appear: what a table of contents is built from.
 *
 * H1, H2 and H3 only. A heading inside an existing table-of-contents block is not part of the
 * document's outline, so the contents never list themselves.
 */
class DocumentOutline
{
    /** @return list<array{level: int, text: string}> */
    public static function headings(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $dom = self::parse($html);
        $found = [];

        // One union query, so the nodes come back in document order, not grouped by level.
        foreach ((new DOMXPath($dom))->query('//h1[not(ancestor::blockquote[@data-toc])] | //h2[not(ancestor::blockquote[@data-toc])] | //h3[not(ancestor::blockquote[@data-toc])]') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            if ($text !== '') {
                $found[] = ['level' => (int) substr($node->nodeName, 1), 'text' => $text];
            }
        }

        return $found;
    }

    /** A fragment of HTML as a DOM, wrapped so every top-level piece is a child of one element. */
    public static function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML('<?xml encoding="UTF-8"><div id="doc-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /** The inner HTML of the wrapper made by parse(). */
    public static function inner(DOMDocument $dom): string
    {
        $root = (new DOMXPath($dom))->query('//*[@id="doc-root"]')->item(0);
        $out = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}
