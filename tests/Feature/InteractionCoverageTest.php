<?php

namespace Tests\Feature;

use Tests\Support\InteractionAudit;
use Tests\TestCase;

/**
 * Fails when a button, or a link styled as one, is added without the shared
 * interaction class. The motion lives in resources/css/app.css; markup opts in
 * with `btn-motion` (filled, standalone) or `interactive` (small controls).
 *
 * Run `php scripts/audit-interactions.php --all` to see every control.
 */
class InteractionCoverageTest extends TestCase
{
    public function test_every_button_and_button_styled_link_carries_a_shared_interaction_class(): void
    {
        $missing = collect(InteractionAudit::scan(resource_path('views')))
            ->where('status', 'missing')
            ->map(fn ($r) => "{$r['file']}:{$r['line']} <{$r['tag']}>")
            ->values()
            ->all();

        $this->assertSame([], $missing, "Add `btn-motion` or `interactive` to:\n".implode("\n", $missing));
    }

    public function test_the_audit_does_catch_an_unstyled_control(): void
    {
        $dir = sys_get_temp_dir().'/audit-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/a.blade.php', '<button type="button" class="rounded-pill bg-ink px-4 py-2">Go</button>'
            .'<button class="btn-motion">Ok</button>'
            .'<a href="/x" class="inline-flex h-9 rounded-pill bg-surface-muted px-4">Link</a>'
            .'<a href="/y" class="text-ink-400 hover:underline">plain link</a>');

        $rows = InteractionAudit::scan($dir);
        unlink($dir.'/a.blade.php');
        rmdir($dir);

        $this->assertSame(['missing', 'covered', 'missing'], array_column($rows, 'status'));
    }

    public function test_every_table_uses_the_shared_row_styling(): void
    {
        foreach (glob(resource_path('views/{,*/,*/*/}*.blade.php'), GLOB_BRACE) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file);

            if (str_contains($path, 'vendor/mail') || str_contains($path, 'components/rich-text')) {
                continue; // email markup, and the document editor's own tables
            }

            preg_match_all('/<table\b[^>]*>/', file_get_contents($file), $m);

            foreach ($m[0] as $table) {
                $this->assertStringContainsString('data-table', $table, "{$path} has a table outside .data-table");
            }
        }
    }

    public function test_the_shared_rules_exist_and_never_lift_table_rows(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['.btn-motion', '.interactive', '.card-hover', '.card-lift', '.data-table tbody tr'] as $selector) {
            $this->assertStringContainsString($selector, $css);
        }

        $this->assertDoesNotMatchRegularExpression('/\.data-table[^{]*tr[^{]*\{[^}]*transform/s', $css);
        $this->assertStringContainsString('.interactive:where(:not(:disabled)):active', $css);
    }
}
