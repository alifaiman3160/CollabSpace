<?php

namespace Tests\Unit;

use App\Models\RubricLevel;
use PHPUnit\Framework\TestCase;

class RubricLevelDisplayTest extends TestCase
{
    public function test_one_mark_is_singular(): void
    {
        $this->assertSame('Developing, 1 mark', RubricLevel::describe('Developing', '1.00'));
    }

    public function test_other_amounts_are_plural_and_trimmed(): void
    {
        $this->assertSame('Good, 1.5 marks', RubricLevel::describe('Good', '1.50'));
        $this->assertSame('Poor, 0 marks', RubricLevel::describe('Poor', '0.00'));
        $this->assertSame('Excellent, 4 marks', RubricLevel::describe('Excellent', 4.0));
    }

    public function test_a_range_wins_and_is_always_plural(): void
    {
        $this->assertSame('Excellent, 4–5 marks', RubricLevel::describe('Excellent', '5.00', '4–5'));
        $this->assertSame('Fair, 1 marks', RubricLevel::describe('Fair', '1.00', '1'));
    }

    public function test_the_model_method_uses_the_same_text(): void
    {
        $level = new RubricLevel(['label' => 'Good', 'marks' => 2, 'marks_range' => null]);

        $this->assertSame('Good, 2 marks', $level->display());
    }
}