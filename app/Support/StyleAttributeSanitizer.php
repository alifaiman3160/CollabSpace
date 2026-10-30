<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The trust boundary for inline styles.
 *
 * Allowing a `style` attribute at all is the riskiest thing the document
 * editor does, because CSS is not inert. A declaration can fetch a remote
 * resource and leak who read the document, and older engines accepted
 * `expression()`, which is arbitrary script. So this does not filter styles —
 * it rebuilds them. Nothing a browser sent is passed through; every surviving
 * declaration is one this class wrote itself from a value it recognised.
 *
 * Six properties are permitted, each with its own validator, and each
 * validator matches a complete, anchored shape rather than looking for
 * something bad. A pattern that says "a colour is six hex digits" cannot be
 * talked into accepting `url(//evil.test)` — there is no clause for it to
 * match. That is the whole design: an allowlist of shapes, not a denylist of
 * payloads.
 *
 * Anything unrecognised is dropped silently. A student who somehow produces a
 * declaration this does not know loses the formatting, never the document.
 */
class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    /**
     * The fonts the toolbar offers, keyed by the primary family a browser
     * reports back.
     *
     * The value is what gets written out, so the stored document always
     * carries a complete stack with a fallback even though the editor only
     * ever sets the first name. Browsers normalise quoting and spacing
     * differently, so the lookup is done on the primary name alone.
     *
     * @var array<string, string>
     */
    public const FONT_FAMILIES = [
        'plus jakarta sans' => "'Plus Jakarta Sans', sans-serif",
        'arial' => 'Arial, Helvetica, sans-serif',
        'georgia' => 'Georgia, serif',
        'times new roman' => "'Times New Roman', Times, serif",
        'courier new' => "'Courier New', Courier, monospace",
    ];

    /**
     * Sizes, as CSS absolute-size keywords rather than numbers.
     *
     * execCommand speaks in these seven steps, and taking them as they come
     * means never parsing a length. There is no unit to confuse, no negative
     * value, and no calc() to reason about.
     *
     * @var list<string>
     */
    public const FONT_SIZES = ['x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large'];

    /** @var list<string> */
    public const LINE_HEIGHTS = ['1', '1.15', '1.5', '2'];

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /**
     * Declarations that are never written out whatever they contain.
     *
     * Belt and braces: the per-property validators below already reject these
     * by not matching them, but a value carrying any of these strings means
     * something is being attempted, and the honest response is to discard the
     * whole attribute rather than keep the parts that looked fine.
     *
     * @var list<string>
     */
    protected const POISON = ['url(', 'expression(', 'javascript:', 'data:', '@import', '/*', '\\'];

    public function getSupportedElements(): ?array
    {
        return null;   // every element the sanitizer allows a style on
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $lower = mb_strtolower($value);

        foreach (self::POISON as $poison) {
            if (str_contains($lower, $poison)) {
                return null;
            }
        }

        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $raw] = explode(':', $declaration, 2);

            $property = mb_strtolower(trim($property));
            $raw = trim($raw);

            $clean = match ($property) {
                'font-family' => $this->fontFamily($raw),
                'font-size' => $this->fontSize($raw),
                'line-height' => $this->lineHeight($raw),
                'color' => $this->colour($raw),
                'background-color' => $this->colour($raw),
                'text-align' => $this->alignment($raw),
                default => null,
            };

            if ($clean !== null) {
                // Written from the validated value, never from the input.
                $kept[$property] = $property.': '.$clean;
            }
        }

        return $kept === [] ? null : implode('; ', $kept);
    }

    /** Matched on the primary family, because browsers requote the rest. */
    protected function fontFamily(string $value): ?string
    {
        $primary = trim(explode(',', $value)[0]);
        $primary = mb_strtolower(trim($primary, "\"' \t"));

        return self::FONT_FAMILIES[$primary] ?? null;
    }

    protected function fontSize(string $value): ?string
    {
        $value = mb_strtolower($value);

        if (in_array($value, self::FONT_SIZES, true)) {
            return $value;
        }

        // Some engines resolve the keyword to a length before we see it. A
        // bare, bounded pixel value is accepted for that reason and no other:
        // no negatives, no decimals, no units beyond px.
        if (preg_match('/^(\d{1,2})px$/', $value, $m) && (int) $m[1] >= 8 && (int) $m[1] <= 48) {
            return $m[1].'px';
        }

        return null;
    }

    protected function lineHeight(string $value): ?string
    {
        $value = rtrim(mb_strtolower($value), '0');
        $value = rtrim($value, '.') ?: $value;

        return in_array($value, self::LINE_HEIGHTS, true) ? $value : null;
    }

    /**
     * Hex or rgb() only.
     *
     * Named colours are excluded deliberately. There are a hundred and
     * forty-odd of them, the list differs between engines, and nothing the
     * toolbar produces needs one — the colour pickers emit hex, and
     * execCommand normalises to rgb(). Two shapes are enough, and two shapes
     * are far easier to be sure about.
     */
    protected function colour(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value)) {
            return $value;
        }

        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', $value, $m)) {
            foreach ([$m[1], $m[2], $m[3]] as $channel) {
                if ((int) $channel > 255) {
                    return null;
                }
            }

            // Rewritten in a canonical form rather than echoed back.
            return sprintf('rgb(%d, %d, %d)', $m[1], $m[2], $m[3]);
        }

        return null;
    }

    protected function alignment(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, self::ALIGNMENTS, true) ? $value : null;
    }
}
