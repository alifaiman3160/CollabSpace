<?php

namespace Tests\Unit;

use App\Models\RubricLevel;
use App\Support\Marking\EvidenceFacts;
use App\Support\Marking\EvidenceType;
use PHPUnit\Framework\TestCase;

/** The plain sentence on a marking row, the records line, and the small marks caption on a chip. */
class MarkingSentenceTest extends TestCase
{
    protected function facts(array $over = []): array
    {
        return $over + [
            'tasks_assigned' => 5, 'tasks_completed' => 4, 'tasks_completed_on_time' => 3, 'tasks_completed_late' => 1,
            'tasks_overdue' => 1, 'late_days' => [10], 'messages_sent' => 15, 'document_edits' => 8, 'files_uploaded' => 1,
            'group_average' => ['messages_sent' => 10, 'document_edits' => 4, 'files_uploaded' => 0.25],
        ];
    }

    public function test_each_evidence_type_has_a_short_plain_sentence_with_its_figures(): void
    {
        $this->assertSame('Finished 4 of 5 tasks (80%).', EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts()));
        $this->assertSame('3 of 5 tasks were done on time, 1 was late (10 days) and 1 is overdue now (60% on time).', EvidenceFacts::sentence(EvidenceType::RELIABILITY, $this->facts()));
        $this->assertSame("Sent 15 messages. The group's average is 10.", EvidenceFacts::sentence(EvidenceType::COMMUNICATION, $this->facts()));
        $this->assertSame("Made 9 edits and uploads. The group's average is 4.25.", EvidenceFacts::sentence(EvidenceType::COLLABORATION, $this->facts()));
    }

    public function test_reliability_reads_naturally_in_each_shape(): void
    {
        $r = fn (array $over) => EvidenceFacts::sentence(EvidenceType::RELIABILITY, $this->facts($over));

        $this->assertSame('5 of 5 tasks were done on time (100%).', $r(['tasks_completed_on_time' => 5, 'tasks_completed_late' => 0, 'tasks_overdue' => 0]));
        $this->assertSame('4 of 5 tasks were done on time and 1 is overdue now (80% on time).', $r(['tasks_completed_on_time' => 4, 'tasks_completed_late' => 0, 'tasks_overdue' => 1]));
        $this->assertSame('3 of 5 tasks were done on time and 2 were late (2 and 5 days) (60% on time).', $r(['tasks_completed_late' => 2, 'late_days' => [2, 5], 'tasks_overdue' => 0]));
        $this->assertSame('2 of 5 tasks were done on time, 1 was late (1 day) and 2 are overdue now (40% on time).', $r(['tasks_completed_on_time' => 2, 'late_days' => [1], 'tasks_overdue' => 2]));
        $this->assertStringContainsString('overdue', $r([]), 'Overdue is named when there is any.');
        $this->assertStringNotContainsString('overdue', $r(['tasks_overdue' => 0]));
    }

    public function test_singular_wording_and_no_sentence_without_anything_to_say(): void
    {
        $this->assertSame('Finished 1 of 1 task (100%).', EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts(['tasks_assigned' => 1, 'tasks_completed' => 1])));
        $this->assertSame("Sent 1 message. The group's average is 10.", EvidenceFacts::sentence(EvidenceType::COMMUNICATION, $this->facts(['messages_sent' => 1])));
        $this->assertSame("Made 1 edit or upload. The group's average is 4.25.", EvidenceFacts::sentence(EvidenceType::COLLABORATION, $this->facts(['document_edits' => 1, 'files_uploaded' => 0])));
        $this->assertNull(EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts(['tasks_assigned' => 0])));
        $this->assertNull(EvidenceFacts::sentence(EvidenceType::NONE, $this->facts()));
    }

    public function test_the_sentences_use_short_common_words(): void
    {
        foreach ([EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY, EvidenceType::COMMUNICATION, EvidenceType::COLLABORATION] as $type) {
            $line = EvidenceFacts::sentence($type, $this->facts());

            $this->assertDoesNotMatchRegularExpression('/indicat|showcas|demonstrat|assigned|completed|rule-based/i', $line, $line);
        }
    }

    public function test_the_records_line_is_short_and_plain(): void
    {
        $this->assertSame('Tasks: 5 given, 4 done', EvidenceFacts::describe(EvidenceType::TASK_COMPLETION, $this->facts()));
        $this->assertSame('Tasks: 5 given, 3 done on time, 1 done late (10 days), 1 overdue now', EvidenceFacts::describe(EvidenceType::RELIABILITY, $this->facts()));
        $this->assertSame('Messages: 15 sent (group average 10)', EvidenceFacts::describe(EvidenceType::COMMUNICATION, $this->facts()));
        $this->assertSame('Edits: 8 (group average 4); Uploads: 1 (group average 0.25)', EvidenceFacts::describe(EvidenceType::COLLABORATION, $this->facts()));
    }

    public function test_the_old_records_wording_is_still_available_for_approvals_made_before(): void
    {
        $this->assertSame('Messages sent 15 (group average 10)', EvidenceFacts::describeLegacy(EvidenceType::COMMUNICATION, $this->facts()));
    }

    public function test_a_chip_caption_gives_just_the_marks(): void
    {
        $this->assertSame('1 mark', RubricLevel::marksText('1.00'));
        $this->assertSame('1.5 marks', RubricLevel::marksText('1.50'));
        $this->assertSame('4–5 marks', RubricLevel::marksText('5.00', '4–5'));
    }
}
