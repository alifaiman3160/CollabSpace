<?php

namespace App\Support;

/**
 * Turns the title attributes of a page into data-tooltip attributes, so the browser's own black tooltip never appears
 * and the app's tooltip (resources/js/tooltip.js) is used instead.
 *
 * Only real attributes on real tags are touched: the <title> element, anything inside a <script> or <style>, a tag
 * that has data-no-tooltip, and frames are left as they are. Quoted attribute values may contain ">" (Alpine
 * expressions do), so tags are read attribute by attribute, never by looking for the next ">".
 */
class TooltipAttributes
{
    /** Tags whose title is not a hint for the person using the page. */
    protected const SKIP = ['title', 'iframe', 'link', 'meta', 'style', 'script', 'html', 'head', 'base'];

    public static function upgrade(string $html): string
    {
        if (! str_contains($html, 'title')) {
            return $html;
        }

        // Script and style blocks are code, not markup.
        $parts = preg_split('#(<script\b.*?</script>|<style\b.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 && str_contains($part, 'title')) {
                $parts[$i] = self::tags($part);
            }
        }

        return implode('', $parts);
    }

    protected static function tags(string $html): string
    {
        $attribute = '\s+[^\s"\'<>\/=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?';

        return preg_replace_callback(
            '/<([a-zA-Z][\w:-]*)((?>'.$attribute.')*+)(\s*\/?)>/',
            function (array $m) use ($attribute) {
                [$whole, $tag, $attributes] = $m;

                if (in_array(strtolower($tag), self::SKIP, true) || ! preg_match('/\stitle\s*=/i', $attributes)
                    || preg_match('/\sdata-no-tooltip(?=[\s=\/>]|$)/', $attributes)) {
                    return $whole;
                }

                $attributes = preg_replace_callback(
                    '/(\s+)(title)(\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))/i',
                    fn (array $a) => $a[1].'data-tooltip'.$a[3],
                    $attributes,
                );

                return '<'.$tag.$attributes.$m[3].'>';
            },
            $html,
        ) ?? $html;
    }
}