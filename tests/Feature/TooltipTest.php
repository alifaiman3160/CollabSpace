<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Subject;
use App\Models\User;
use App\Support\ContributionTooltip;
use App\Support\TooltipAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The one tooltip: title attributes leave the server as data-tooltip, and the contribution bar has a rich tooltip. */
class TooltipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_title_becomes_a_data_tooltip(): void
    {
        $this->assertSame('<button type="button" data-tooltip="Delete">x</button>', TooltipAttributes::upgrade('<button type="button" title="Delete">x</button>'));
        $this->assertSame("<a href=\"/a\" data-tooltip='Its'>x</a>", TooltipAttributes::upgrade("<a href=\"/a\" title='Its'>x</a>"));
    }

    public function test_a_quoted_value_with_a_greater_than_sign_does_not_end_the_tag(): void
    {
        $html = '<button x-on:click="a > b" title="Next (Alt + Right)" class="x">go</button>';

        $this->assertSame('<button x-on:click="a > b" data-tooltip="Next (Alt + Right)" class="x">go</button>', TooltipAttributes::upgrade($html));
    }

    public function test_the_document_title_scripts_frames_and_opt_outs_are_left_alone(): void
    {
        foreach ([
            '<title>Page</title>',
            '<head><title>title="x"</title></head>',
            '<script>const a = "<b title=\"x\">";</script>',
            '<iframe src="/x" title="Preview"></iframe>',
            '<span title="Mine" data-no-tooltip>x</span>',
            '<p>text title="not an attribute"</p>',
            '<svg><title>Chart</title></svg>',
        ] as $html) {
            $this->assertSame($html, TooltipAttributes::upgrade($html), $html);
        }
    }

    public function test_data_title_and_aria_attributes_are_not_touched(): void
    {
        $html = '<article data-title="Apple" aria-label="title"></article>';

        $this->assertSame($html, TooltipAttributes::upgrade($html));
    }

    public function test_the_percentage_is_left_out_when_the_group_has_no_points(): void
    {
        $this->assertNull(ContributionTooltip::percent(0, 0));
        $this->assertSame(11, ContributionTooltip::percent(18, 160));
        $this->assertSame('18 points · 11% of the group', ContributionTooltip::line(18, 11));
        $this->assertSame('0 points', ContributionTooltip::line(0, null));
        $this->assertSame('1 point · 100% of the group', ContributionTooltip::line(1, 100));
        $this->assertSame('Farah Aqilah Roslan, 18 points, 11 percent', ContributionTooltip::label('Farah Aqilah Roslan', 18, 11));
        $this->assertSame('Ali, 0 points', ContributionTooltip::label('Ali', 0, null));
        $this->assertSame('FA', ContributionTooltip::initials('Farah Aqilah Roslan'));
    }

    public function test_the_report_page_has_no_native_title_and_its_segments_are_focusable_and_named(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'AAA1111', 'name' => 'Name', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Team', 'group_size' => 4]);

        foreach ([30, 20, 1] as $score) {
            $student = User::factory()->student()->create();
            GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);
            ActivityLog::create(['user_id' => $student->id, 'group_id' => $group->id, 'activity_type' => 'task_completed', 'weight' => $score]);
        }

        $html = $this->actingAs($lecturer)->get(route('lecturer.reports'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<(?!title|svg)[^>]*\stitle="/', preg_replace('#<head>.*?</head>#s', '', $html));
        $this->assertSame(3, preg_match_all('/data-segment[^>]*tabindex="0"|tabindex="0"[^>]*data-segment/', $html));
        $this->assertStringContainsString('51 points', $html);
        $this->assertMatchesRegularExpression('/aria-label="[^"]+, 30 points, 59 percent"/', $html);
        $this->assertStringContainsString('30 points · 59% of the group', $html);
        $this->assertSame(1, substr_count($html, 'data-low'), 'Only the member far below the others gets the Low pill.');
    }

    public function test_the_main_pages_of_every_role_have_no_native_title_on_what_can_be_used(): void
    {
        $lecturer = User::factory()->lecturer()->create(['must_change_password' => false]);
        $subject = Subject::create(['lecturer_id' => $lecturer->id, 'code' => 'BBB2222', 'name' => 'Name', 'group_size' => 4]);
        $group = Group::create(['subject_id' => $subject->id, 'name' => 'Team', 'group_size' => 4]);
        $student = User::factory()->student()->create(['must_change_password' => false]);
        GroupMember::create(['group_id' => $group->id, 'user_id' => $student->id, 'joined_at' => now()]);

        $pages = [
            [$lecturer, route('lecturer.dashboard')],
            [$lecturer, route('lecturer.reports')],
            [$lecturer, route('lecturer.progress.show', $group)],
            [$student, route('student.dashboard')],
            [$student, route('student.documents.group', $group)],
            [$student, route('student.workspace.show', $group)],
            [$student, route('student.board.show', $group)],
            [$student, route('student.chat.show', $group)],
        ];

        foreach ($pages as [$user, $url]) {
            $response = $this->actingAs($user)->get($url);
            $this->assertSame(200, $response->getStatusCode(), $url.' -> '.$response->headers->get('Location'));
            $html = $response->getContent();
            $body = preg_replace(['#<head>.*?</head>#s', '#<svg\b.*?</svg>#s', '#<script\b.*?</script>#s'], '', $html);

            $this->assertDoesNotMatchRegularExpression('/<(?!iframe)[^>]*\stitle="/', $body, $url.' still has a native title.');
        }
    }
}
