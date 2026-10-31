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
    /**
     * Tags the editor toolbar can produce.
     *
     * Everything here is something a button in <x-rich-text> emits. Nothing is
     * allowed "just in case": an element nobody can create is an element
     * nobody can review, and it only widens what a paste or a hand-written
     * payload has to aim at.
     *
     * One picture element, and only one kind of picture: an <img> whose address is
     * this app's own authenticated route (see DocumentAttributeSanitizer). No
     * iframe, no video, no foreign or data: image.
     */
    public const ALLOWED_TAGS = [
        'p', 'br',
        'strong', 'b', 'em', 'i', 'u', 's',
        // Superscript and subscript: references, units, formulae.
        'sup', 'sub',
        'h1', 'h2', 'h3',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
        // A picture with an optional caption.
        'figure', 'figcaption', 'img',
        // Carries the inline formatting: font, size, colour, highlight.
        'span',
        // A simple grid. No caption, no colgroup, no merged cells — the
        // toolbar inserts a plain rectangle and that is all it has to accept.
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    /**
     * Elements that may carry a style attribute.
     *
     * Every value inside it is rebuilt by StyleAttributeSanitizer, which is
     * where the actual safety lives — this list only decides where a style may
     * appear at all. Block elements need it for alignment and line spacing;
     * span needs it for everything inline.
     *
     * @var list<string>
     */
    public const STYLEABLE_TAGS = [
        'span', 'p', 'h1', 'h2', 'h3', 'li', 'blockquote', 'table', 'tr', 'th', 'td',
    ];

    /**
     * Attributes, besides style, that a tag may carry. Each value is rebuilt by
     * DocumentAttributeSanitizer, which is where they are checked.
     *
     * @var array<string, list<string>>
     */
    public const EXTRA_ATTRIBUTES = [
        'img' => ['src', 'alt', 'data-image'],
        'figure' => ['data-size'],
        // The page-break and table-of-contents markers.
        'p' => ['data-page-break'],
        'blockquote' => ['data-toc'],
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
            $attributes = match (true) {
                // Links keep href only: target and rel are forced below, and nothing else survives.
                $tag === 'a' => ['href'],
                in_array($tag, self::STYLEABLE_TAGS, true) => ['style'],
                default => [],
            };

            $config = $config->allowElement($tag, array_merge($attributes, self::EXTRA_ATTRIBUTES[$tag] ?? []));
        }

        // Every surviving style declaration is rebuilt by this, not filtered.
        // Allowing the attribute above only decides where one may appear; what
        // it is permitted to say is decided there.
        $config = $config->withAttributeSanitizer(new StyleAttributeSanitizer());
        $config = $config->withAttributeSanitizer(new DocumentAttributeSanitizer());

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'template', 'noscript', 'svg', 'math'] as $dangerous) {
            $config = $config->dropElement($dangerous);
        }

        $config = $config
            // Any scheme not listed is stripped, so javascript: and data: URLs
            // cannot survive on a link. Web addresses only.
            ->allowLinkSchemes(['https', 'http'])
            ->allowRelativeLinks(false)
            // Every link opens in a new tab and cannot reach back to this page or pass on a referrer's weight.
            ->forceAttribute('a', 'target', '_blank')
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            // A picture's address is a relative one (this app's own route). The attribute sanitiser then
            // lets through that exact shape and nothing else, so a foreign or data: image is removed.
            ->allowRelativeMedias(true)
            ->allowMediaSchemes(['https', 'http'])
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

        $clean = $this->sanitizer->sanitize($html);

        // A picture whose address did not pass is removed outright, and a link whose address did not
        // pass keeps its words but loses the anchor, rather than leaving an empty tag behind.
        $clean = preg_replace('#<img(?![^>]*\ssrc=)[^>]*>#i', '', $clean) ?? $clean;
        $clean = preg_replace('#<a(?![^>]*\shref=)[^>]*>(.*?)</a>#is', '$1', $clean) ?? $clean;

        return trim($clean);
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
