<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The small attributes the editor's newer blocks carry, each rebuilt from a value this class recognises.
 *
 *   img src            only one of this app's own picture addresses, never a foreign or data: URL
 *   img data-image     the picture's id, digits only
 *   img alt            plain text, short
 *   figure data-size   small, medium or full
 *   p data-page-break  the page-break marker
 *   blockquote data-toc the table-of-contents marker
 */
class DocumentAttributeSanitizer implements AttributeSanitizerInterface
{
    public const SIZES = ['small', 'medium', 'full'];

    /** The only address an image may have: the authenticated route, relative. */
    public const IMAGE_SRC = '#^/documents/images/\d+$#';

    public function getSupportedElements(): ?array
    {
        return ['img', 'figure', 'p', 'blockquote'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src', 'alt', 'data-image', 'data-size', 'data-page-break', 'data-toc'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return match ($attribute) {
            'src' => $element === 'img' && preg_match(self::IMAGE_SRC, $value) === 1 ? $value : null,
            'data-image' => $element === 'img' && ctype_digit($value) ? $value : null,
            'alt' => $element === 'img' ? mb_substr(trim(strip_tags($value)), 0, 200) : null,
            'data-size' => $element === 'figure' ? (in_array($value, self::SIZES, true) ? $value : 'medium') : null,
            'data-page-break' => $element === 'p' ? '1' : null,
            'data-toc' => $element === 'blockquote' ? '1' : null,
            default => null,
        };
    }
}
