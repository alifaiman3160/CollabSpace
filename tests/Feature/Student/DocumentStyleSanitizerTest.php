<?php

namespace Tests\Feature\Student;

use App\Support\DocumentSanitizer;
use App\Support\StyleAttributeSanitizer;
use Tests\TestCase;

/**
 * The style attribute, which is the riskiest thing the document editor does.
 *
 * CSS is not inert. A declaration can fetch a remote resource and leak who
 * read a document and when, and older engines executed expression(). So the
 * sanitizer does not filter styles, it rebuilds them: nothing the browser sent
 * is passed through, and every surviving declaration was written by
 * StyleAttributeSanitizer from a value it recognised.
 *
 * There is an injection attempt here for each of the six permitted properties,
 * because a validator that is strict for colour and loose for font-family is
 * exactly as weak as its loosest clause.
 */
class DocumentStyleSanitizerTest extends TestCase
{
    protected DocumentSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new DocumentSanitizer();
    }

    protected function styled(string $style, string $tag = 'span'): string
    {
        return $this->sanitizer->clean("<p><{$tag} style=\"{$style}\">text</{$tag}></p>");
    }

    /*
    | Each allowed property, attacked
    */

    public static function injectionProvider(): array
    {
        return [
            'font-family carrying a fetch' => ['font-family: url(//evil.test/leak.woff)'],
            'font-family with an import' => ['font-family: Arial; @import url(//evil.test/x.css)'],
            'font-family escaping out' => ['font-family: "Arial\\22 onload=\\22 alert(1)"'],

            'font-size with expression' => ['font-size: expression(alert(1))'],
            'font-size with a payload after' => ['font-size: 12px; behavior: url(#default#time2)'],
            'font-size negative' => ['font-size: -9999px'],

            'line-height with a fetch' => ['line-height: url(//evil.test/x)'],
            'line-height with script' => ['line-height: javascript:alert(1)'],

            'color with a fetch' => ['color: url(//evil.test/pixel.png)'],
            'color with expression' => ['color: expression(alert(1))'],
            'color with a comment break' => ['color: red/*x*/; background: url(//evil.test)'],

            'background-color with a fetch' => ['background-color: url(//evil.test/pixel.gif)'],
            'background-color as shorthand' => ['background: url(//evil.test/pixel.gif)'],
            // The raw '<' closes the attribute as far as an HTML parser is
            // concerned, so this is really markup injection wearing a style's
            // clothes. The surrounding text is lost along with the payload,
            // which is the right trade and the reason this one case does not
            // assert that the writing survives.
            'background-color with data uri' => ['background-color: #fff; background-image: data:image/svg+xml,<svg onload=alert(1)>', false],

            'text-align with a fetch' => ['text-align: url(//evil.test)'],
            'text-align with junk' => ['text-align: center; position: fixed; z-index: 99999'],
        ];
    }

    /**
     * @dataProvider injectionProvider
     */
    public function test_an_injection_attempt_leaves_nothing_dangerous(string $style, bool $textSurvives = true): void
    {
        $output = $this->styled($style);

        // For anything the editor could actually produce, the writing survives
        // and only the formatting is at risk.
        if ($textSurvives) {
            $this->assertStringContainsString('text', $output);
        }

        foreach (['url(', 'expression(', 'javascript:', 'data:', '@import', 'behavior', 'onload', 'position', 'z-index'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $output,
                "“{$forbidden}” survived sanitising of: {$style}");
        }
    }

    /*
    | What is allowed through, and in what shape
    */

    public function test_each_permitted_property_survives_with_a_valid_value(): void
    {
        $this->assertStringContainsString("font-family: Georgia, serif", $this->styled('font-family: Georgia'));
        $this->assertStringContainsString('font-size: large', $this->styled('font-size: large'));
        $this->assertStringContainsString('line-height: 1.5', $this->styled('line-height: 1.5', 'p'));
        $this->assertStringContainsString('color: #b7291f', $this->styled('color: #b7291f'));
        $this->assertStringContainsString('background-color: #fff3bf', $this->styled('background-color: #fff3bf'));
        $this->assertStringContainsString('text-align: center', $this->styled('text-align: center', 'p'));
    }

    public function test_a_colour_is_rewritten_into_a_canonical_form(): void
    {
        // Not echoed back: the output is composed from the three integers that
        // were parsed, so nothing rides along inside the original text.
        $this->assertStringContainsString('rgb(224, 69, 58)', $this->styled('color: RGB( 224 ,69,  58 )'));
    }

    public function test_an_out_of_range_colour_channel_is_refused(): void
    {
        $this->assertStringNotContainsString('rgb(', $this->styled('color: rgb(999, 0, 0)'));
    }

    public function test_an_unknown_font_is_refused_even_though_it_looks_harmless(): void
    {
        // The allowlist is the toolbar's own list. A font nobody can pick is a
        // font nobody reviewed.
        $this->assertStringNotContainsString('font-family', $this->styled('font-family: Comic Sans MS'));
    }

    public function test_a_font_size_outside_the_sane_range_is_refused(): void
    {
        $this->assertStringNotContainsString('font-size', $this->styled('font-size: 400px'));
        $this->assertStringContainsString('font-size: 16px', $this->styled('font-size: 16px'));
    }

    public function test_good_declarations_survive_beside_a_rejected_one(): void
    {
        // One bad property must not cost the writer their whole paragraph's
        // formatting, as long as nothing poisonous was attempted.
        $output = $this->styled('color: #1b6b45; position: absolute; text-align: right', 'p');

        $this->assertStringContainsString('color: #1b6b45', $output);
        $this->assertStringContainsString('text-align: right', $output);
        $this->assertStringNotContainsString('position', $output);
    }

    public function test_one_poisonous_declaration_discards_the_whole_attribute(): void
    {
        // Anything carrying url() means something is being attempted, and the
        // honest answer is to drop the lot rather than keep the parts that
        // happened to look fine.
        $output = $this->styled('color: #1b6b45; background-image: url(//evil.test/x)');

        $this->assertStringNotContainsString('style=', $output);
        $this->assertStringContainsString('text', $output);
    }

    /*
    | Where a style may appear at all
    */

    public function test_style_is_allowed_only_on_the_elements_that_need_it(): void
    {
        foreach (DocumentSanitizer::STYLEABLE_TAGS as $tag) {
            $this->assertContains($tag, DocumentSanitizer::ALLOWED_TAGS,
                "{$tag} may carry a style but is not an allowed element.");
        }

        // A link is not styleable: its one attribute is href, and nothing else.
        $output = $this->sanitizer->clean('<p><a href="https://x.test" style="color: #b7291f">link</a></p>');

        $this->assertStringContainsString('href', $output);
        $this->assertStringNotContainsString('style', $output);
    }

    public function test_the_toolbar_cannot_offer_what_the_sanitizer_refuses(): void
    {
        // The editor builds its menus from these same lists, so a mismatch
        // would mean a control that silently does nothing on save.
        $this->assertNotEmpty(StyleAttributeSanitizer::FONT_FAMILIES);
        $this->assertNotEmpty(StyleAttributeSanitizer::FONT_SIZES);
        $this->assertNotEmpty(StyleAttributeSanitizer::LINE_HEIGHTS);
        $this->assertSame(['left', 'center', 'right', 'justify'], StyleAttributeSanitizer::ALIGNMENTS);

        foreach (StyleAttributeSanitizer::LINE_HEIGHTS as $height) {
            $this->assertStringContainsString("line-height: {$height}", $this->styled("line-height: {$height}", 'p'));
        }
    }

    /*
    | The new elements
    */

    public function test_a_simple_table_survives(): void
    {
        $output = $this->sanitizer->clean(
            '<table><thead><tr><th>Week</th></tr></thead><tbody><tr><td>Draft</td></tr></tbody></table>'
        );

        foreach (['<table>', '<thead>', '<tbody>', '<tr>', '<th>', '<td>', 'Week', 'Draft'] as $fragment) {
            $this->assertStringContainsString($fragment, $output);
        }
    }

    public function test_a_table_cannot_smuggle_an_event_handler(): void
    {
        $output = $this->sanitizer->clean('<table onmouseover="alert(1)"><tr><td onclick="alert(1)">x</td></tr></table>');

        $this->assertStringNotContainsString('onmouseover', $output);
        $this->assertStringNotContainsString('onclick', $output);
        $this->assertStringContainsString('x', $output);
    }

    public function test_rendering_a_stored_document_sanitises_it_again(): void
    {
        // Defence in depth: a row written before these rules existed, or by
        // anything other than the editor, is cleaned on the way out too.
        $rendered = $this->sanitizer->render('<p style="text-align: center; position: fixed">Middle</p>');

        $this->assertStringContainsString('text-align: center', $rendered);
        $this->assertStringNotContainsString('position', $rendered);
    }

    public function test_restyling_the_same_words_still_counts_as_a_change(): void
    {
        // It has to: the version history is what makes the formatting
        // recoverable, and a restyle that wrote no version could not be undone.
        $this->assertFalse($this->sanitizer->isUnchanged(
            '<p>Chapter one</p>',
            '<p style="text-align: center">Chapter one</p>',
        ));
    }

    public function test_resaving_identical_styled_content_is_still_a_no_op(): void
    {
        // The existing rule, unchanged by any of this: opening a document and
        // pressing save must not write a version or score a contribution.
        $styled = '<p style="text-align: center"><span style="color: #b7291f">Chapter one</span></p>';

        $this->assertTrue($this->sanitizer->isUnchanged($styled, $styled));
        $this->assertTrue($this->sanitizer->isUnchanged($styled, "  {$styled}  "));
    }

    public function test_nothing_that_loads_a_remote_resource_is_allowed(): void
    {
        // No img, no iframe, no video. Files belong in group chat, where they
        // are served through an authenticated route.
        foreach ([
            '<p><img src="https://evil.test/pixel.gif"></p>',
            '<p><iframe src="https://evil.test"></iframe></p>',
            '<p><video src="https://evil.test/x.mp4"></video></p>',
        ] as $html) {
            $output = $this->sanitizer->clean($html);

            $this->assertStringNotContainsString('evil.test', $output);
        }
    }
}
