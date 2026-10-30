<?php

namespace Tests\Support;

/**
 * Static scan of the Blade views for controls that bypass the shared
 * interaction classes (see "Motion" in resources/css/app.css).
 *
 * A <button> is always a control. An <a> counts as one only when its classes
 * give it a button's shape or fill (a pill, a filled background, a fixed size);
 * a plain text link is covered by the global link rules instead.
 *
 * Covered means the element carries `btn-motion` (filled, lifts) or
 * `interactive` (small controls: colour and press only). Exempt is a short,
 * explicit list with a reason each.
 */
class InteractionAudit
{
    /** Classes that mark an element as having been through the shared system. */
    private const COVERED = ['btn-motion', 'interactive', 'nav-motion', 'card-hover', 'card-lift', 'motion-colors'];

    /**
     * Elements that deliberately take no hover movement. Matched on the file
     * and on a fragment of the tag.
     */
    private const EXEMPT = [
        // Shared components that themselves carry the class through a variable.
        ['components/button.blade.php', '{{ $tag }}', 'carries btn-motion via $classes'],
        ['components/table/quiet-action.blade.php', '', 'classes come from $classes, which includes interactive'],
        // Mail templates are rendered by mail clients, which ignore app CSS.
        ['vendor/mail/', '', 'email markup, not the app stylesheet'],
        // Livewire/Blade drag handle area: the draggable card itself must not move.
        ['livewire/task-board.blade.php', 'draggable="true"', 'draggable card; movement would fight the drag'],
    ];

    /** @return list<array{file:string,line:int,tag:string,status:string,why:string}> */
    public static function scan(string $root): array
    {
        $rows = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/',substr($file->getPathname(), strlen($root) + 1));
            $source = file_get_contents($file->getPathname());

            foreach (self::tags($source) as [$name, $tag, $offset]) {
                $classes = self::classes($tag);
                $isControl = $name === 'button' || self::looksLikeButton($classes);

                if (! $isControl) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $offset) + 1;
                $status = 'missing';
                $why = 'no shared interaction class';

                if (preg_match('/(?<![\w-])('.implode('|', self::COVERED).')(?![\w-])/', $classes)) {
                    $status = 'covered';
                    $why = '';
                } else {
                    foreach (self::EXEMPT as [$path, $needle, $reason]) {
                        if (str_contains($relative, $path) && ($needle === '' || str_contains($tag, $needle))) {
                            $status = 'exempt';
                            $why = $reason;
                        }
                    }
                }

                $rows[] = ['file' => $relative, 'line' => $line, 'tag' => $name, 'status' => $status, 'why' => $why];
            }
        }

        usort($rows, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /** @return list<array{0:string,1:string,2:int}> name, whole opening tag, offset */
    private static function tags(string $source): array
    {
        $found = [];

        preg_match_all('/<(button|a)(?=[\s>])/', $source, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as [$text, $offset]) {
            $name = substr($text, 1);
            $i = $offset + strlen($text);
            $quote = null;
            $length = strlen($source);

            // Walk to the closing ">" while respecting quotes, so "->" inside an
            // attribute does not end the tag early.
            while ($i < $length) {
                $c = $source[$i];

                if ($quote !== null) {
                    if ($c === $quote) {
                        $quote = null;
                    }
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>' && $source[$i - 1] !== '-' && $source[$i - 1] !== '=') {
                    break;
                }

                $i++;
            }

            $found[] = [$name, substr($source, $offset, $i - $offset + 1), $offset];
        }

        return $found;
    }

    /** The opening tag, flattened: class lists live in class=, :class and merge() arrays alike. */
    private static function classes(string $tag): string
    {
        return $tag;
    }

    private static function looksLikeButton(string $classes): bool
    {
        // A pill or filled, padded shape: what a link looks like when it is a button.
        $filled = (bool) preg_match('/\b(bg-(ink|surface-muted|surface-hover|periwinkle|danger|success)\b|bg-ink)/', $classes);
        $shaped = (bool) preg_match('/\brounded-(pill|\[1\d+px\])|\bsize-\d+/', $classes);

        return $filled && $shaped && (bool) preg_match('/\b(px-|py-|size-|h-)/', $classes);
    }
}
