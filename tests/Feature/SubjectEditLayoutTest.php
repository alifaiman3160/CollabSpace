<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The two-column subject pages keep one form with a Save in the header and one at the end; the sidebar scrolls without a bar. */
class SubjectEditLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecturer_edit_page_is_one_form_with_both_save_buttons(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'SECJ3104', 'name' => 'App Dev', 'group_size' => 4]);

        $html = $this->actingAs($lecturer)->get(route('lecturer.subjects.edit', $subject))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="subject-form"'));
        $this->assertStringContainsString('form="subject-form"', $html);
        $this->assertStringContainsString('data-save-header', $html);
        $this->assertStringContainsString('data-edit-stack', $html);
        $this->assertStringNotContainsString('data-edit-grid', $html);
    }

    public function test_admin_subject_pages_use_two_cards_and_a_header_save(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        $html = $this->actingAs($admin)->get(route('admin.subjects.create'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="subject-form"'));
        $this->assertStringContainsString('form="subject-form"', $html);
        $this->assertStringContainsString('data-details-card', $html);
        $this->assertStringContainsString('data-subject-sections-block', $html);
    }

    public function test_sidebar_scrollbar_is_hidden_in_css_and_collapse_markup_is_intact(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.cs-sidebar::-webkit-scrollbar', $css);
        $this->assertStringContainsString('overscroll-behavior: contain', $css);

        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $html = $this->actingAs($lecturer)->get(route('lecturer.subjects'))->assertOk()->getContent();
        $this->assertStringContainsString('cs-sidebar', $html);
        $this->assertStringContainsString('id="sidebar-nav"', $html);
    }
}
