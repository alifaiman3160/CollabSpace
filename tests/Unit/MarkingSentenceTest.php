<?php

namespace Tests\Unit;

use App\Models\RubricLevel;
use App\Support\Marking\EvidenceFacts;
use App\Support\Marking\EvidenceType;
use PHPUnit\Framework\TestCase;

/** The one short line on a marking row, and the small marks caption on a chip. */
class MarkingSentenceTest extends TestCase
{
    protected function facts(array $over = []): array
    {
        return $over + [
            'tasks_assigned' => 4, 'tasks_completed' => 3, 'tasks_completed_on_time' => 2, 'tasks_completed_late' => 1,
            'tasks_overdue' => 1, 'late_days' => [2], 'messages_sent' => 10, 'document_edits' => 8, 'files_uploaded' => 2,
            'group_average' => ['messages_sent' => 5, 'document_edits' => 4, 'files_uploaded' => 2],
        ];
    }

    public function test_each_evidence_type_has_a_short_plain_sentence_with_its_figures(): void
    {
        $this->assertSame('Finished 3 of 4 assigned tasks.', EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts()));
        $this->assertSame('2 of 4 tasks on time, 1 still overdue.', EvidenceFacts::sentence(EvidenceType::RELIABILITY, $this->facts()));
        $this->assertSame('Sent 10 messages; the group average is 5.', EvidenceFacts::sentence(EvidenceType::COMMUNICATION, $this->facts()));
        $this->assertSame('10 edits and uploads; the group average is 6.', EvidenceFacts::sentence(EvidenceType::COLLABORATION, $this->facts()));
    }

    public function test_reliability_names_overdue_only_when_there_is_some(): void
    {
        $this->assertSame('4 of 4 tasks on time.', EvidenceFacts::sentence(EvidenceType::RELIABILITY, $this->facts(['tasks_completed_on_time' => 4, 'tasks_overdue' => 0])));
    }

    public function test_singular_wording_and_no_sentence_without_anything_to_say(): void
    {
        $this->assertSame('Finished 1 of 1 assigned task.', EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts(['tasks_assigned' => 1, 'tasks_completed' => 1])));
        $this->assertSame('Sent 1 message; the group average is 5.', EvidenceFacts::sentence(EvidenceType::COMMUNICATION, $this->facts(['messages_sent' => 1])));
        $this->assertNull(EvidenceFacts::sentence(EvidenceType::TASK_COMPLETION, $this->facts(['tasks_assigned' => 0])));
        $this->assertNull(EvidenceFacts::sentence(EvidenceType::NONE, $this->facts()));
    }

    public function test_the_sentences_are_short_and_avoid_the_stiff_words(): void
    {
        foreach ([EvidenceType::TASK_COMPLETION, EvidenceType::RELIABILITY, EvidenceType::COMMUNICATION, EvidenceType::COLLABORATION] as $type) {
            $line = EvidenceFacts::sentence($type, $this->facts());

            $this->assertLessThanOrEqual(15, str_word_count($line), $line);
            $this->assertDoesNotMatchRegularExpression('/indicat|showcas|demonstrat/i', $line);
        }
    }

    public function test_a_chip_caption_gives_just_the_marks(): void
    {
        $this->assertSame('1 mark', RubricLevel::marksText('1.00'));
        $this->assertSame('1.5 marks', RubricLevel::marksText('1.50'));
        $this->assertSame('4–5 marks', RubricLevel::marksText('5.00', '4–5'));
    }
}
