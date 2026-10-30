<?php

namespace Tests\Unit\Ai;

use App\Support\Ai\AiException;
use App\Support\Ai\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['level', 'rationale'],
        'properties' => [
            'level' => ['type' => 'string', 'enum' => ['a', 'b']],
            'rationale' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 10],
            'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 5],
            'tags' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string']],
        ],
        'additionalProperties' => false,
    ];

    public function test_a_valid_answer_passes(): void
    {
        SchemaValidator::assert(['level' => 'a', 'rationale' => 'fine', 'score' => 4.5, 'tags' => ['x']], self::SCHEMA);

        $this->addToAssertionCount(1);
    }

    public static function invalid(): array
    {
        return [
            'not an object' => [['a', 'b']],
            'missing required' => [['level' => 'a']],
            'outside enum' => [['level' => 'z', 'rationale' => 'fine']],
            'wrong type' => [['level' => 'a', 'rationale' => 123]],
            'too short' => [['level' => 'a', 'rationale' => 'hi']],
            'too long' => [['level' => 'a', 'rationale' => 'far too long here']],
            'above maximum' => [['level' => 'a', 'rationale' => 'fine', 'score' => 9]],
            'extra field' => [['level' => 'a', 'rationale' => 'fine', 'sneaky' => 1]],
            'too many items' => [['level' => 'a', 'rationale' => 'fine', 'tags' => ['a', 'b', 'c']]],
            'bad item type' => [['level' => 'a', 'rationale' => 'fine', 'tags' => [1]]],
            'bool is not a number' => [['level' => 'a', 'rationale' => 'fine', 'score' => true]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function test_an_invalid_answer_is_rejected(array $data): void
    {
        $this->expectException(AiException::class);

        SchemaValidator::assert($data, self::SCHEMA);
    }
}
