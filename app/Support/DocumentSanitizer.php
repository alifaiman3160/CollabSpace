<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The trust boundary for document bodies.
 *
 * A document is written by one student and rendered as HTML to everyone else in
 * the group, so anything that survives this class runs in their browsers. The
 * allowlist is deliberately tiny — exactly what the editor toolbar can produce
 * — and everything else is dropped rather than escaped, including <script>,
 * <style>, <iframe>, every on* handler and every javascript: URL.
 *
 * Built on symfony/html-sanitizer rather than a hand-rolled regex or strip_tags:
 * this is precisely the kind of parsing where home-grown filters get bypassed.
 */
class DocumentSanitizer
{
    /** Tags the editor toolbar can produce. */
    public const ALLOWED_TAGS = [
        'p', 'br',
        'strong', 'b', 'em', 'i', 'u', 's',
        'h2', 'h3',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
    ];

    protected HtmlSanitizer $sanitizer;

    public function __construct()
    {
        // Note: allowStaticElements() is deliberately NOT used — it permits the
        // whole W3C static set (tables, divs, images, spans...). This starts
        // from nothing instead.
        //
        // Block, not Drop, is the default for unlisted tags: an unknown wrapper
        // is unwrapped and its text kept, so pasting from Word loses the markup
        // but not the writing. The genuinely dangerous elements below are
        // Dropped instead, which discards their contents too.
        $config = (new HtmlSanitizerConfig())
            ->defaultAction(HtmlSanitizerAction::Block);

        foreach (self::ALLOWED_TAGS as $tag) {
            $config = $tag === 'a'
                // Links keep href only — no target, no on* handlers.
                ? $config->allowElement('a', ['href'])
                : $config->allowElement($tag, []);
        }

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'template', 'noscript', 'svg', 'math'] as $dangerous) {
            $config = $config->dropElement($dangerous);
        }

        $config = $config
            // Any scheme not listed is stripped, so javascript: and data: URLs
            // cannot survive on a link.
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowRelativeLinks(false)
            ->forceHttpsUrls(false)
            ->withMaxInputLength(200_000);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Raw-text elements, whose contents are code rather than prose.
     *
     * With a Block default the sanitizer unwraps unknown tags and keeps their
     * text, which is what we want for a stray <div> — but for these it would
     * spill stylesheet or script source into the document as visible text.
     * They are removed wholesale first.
     *
     * This is tidiness, not the security control: the sanitizer below is the
     * boundary and runs on the result regardless.
     */
    protected const RAW_TEXT_ELEMENTS = ['script', 'style', 'title', 'textarea', 'noscript', 'template', 'svg', 'math'];

    /** Cleans a document body for storage. */
    public function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $html = $this->stripRawTextElements($html);

        return trim($this->sanitizer->sanitize($html));
    }

    protected function stripRawTextElements(string $html): string
    {
        $pattern = '#<('.implode('|', self::RAW_TEXT_ELEMENTS).')\b[^>]*>.*?</\1\s*>#is';

        // Repeat until stable, so nested or spliced tags cannot reconstitute one.
        do {
            $previous = $html;
            $html = preg_replace($pattern, '', $html) ?? $html;
        } while ($html !== $previous);

        // Unclosed openers (<script> with no </script>) never reach the browser
        // as markup, but drop the remainder rather than leaving source behind.
        return preg_replace('#<('.implode('|', self::RAW_TEXT_ELEMENTS).')\b[^>]*>.*#is', '', $html) ?? $html;
    }

    /**
     * True when two bodies are the same piece of writing.
     *
     * Compared after sanitising and after collapsing whitespace, so that
     * re-opening a document and saving it untouched does not create a version
     * row — and therefore does not inflate the author's contribution score.
     * Same reasoning as the message cap in config/contribution.php.
     */
    public function isUnchanged(?string $a, ?string $b): bool
    {
        return $this->normalise($a) === $this->normalise($b);
    }

    protected function normalise(?string $html): string
    {
        $clean = $this->clean($html);

        // An empty editor reports itself in several equivalent ways.
        $clean = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/iu', '', $clean) ?? $clean;
        $clean = str_replace('&nbsp;', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }

    /**
     * Renders stored content for display.
     *
     * Sanitises again on the way out — defence in depth, and it also covers
     * rows written before this class existed (the seeder stores plain text with
     * newlines, which is escaped and given line breaks rather than swallowed).
     */
    public function render(?string $content): string
    {
        $content = (string) $content;

        if (trim($content) === '') {
            return '';
        }

        // Plain text: escape it, then honour its line breaks.
        if (! preg_match('/<[a-z][\s\S]*>/i', $content)) {
            return nl2br(e($content));
        }

        return $this->clean($content);
    }
}
