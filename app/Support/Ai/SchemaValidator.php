<?php

namespace App\Support\Ai;

/**
 * Checks a decoded answer against a JSON Schema.
 *
 * Deliberately a small subset, enough for the structured answers this
 * application asks for and nothing it does not need, so there is no dependency
 * to carry: type (object, array, string, integer, number, boolean, null),
 * required, properties, additionalProperties (false), enum, const, items,
 * minLength, maxLength, minimum, maximum, minItems, maxItems.
 *
 * Strict by intent. A model that returns an extra field, a string where a
 * number was asked for, or a value outside the enum is treated as having not
 * answered, not as having answered approximately.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws AiException  describing the first place the data does not fit
     */
    public static function assert(mixed $data, array $schema): void
    {
        $problem = self::problem($data, $schema, 'answer');

        if ($problem !== null) {
            throw AiException::invalidResponse($problem);
        }
    }

    /** @param  array<string, mixed>  $schema */
    public static function problem(mixed $data, array $schema, string $path = 'answer'): ?string
    {
        if (isset($schema['const']) && $data !== $schema['const']) {
            return "{$path} is not the expected value";
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            return "{$path} is not one of the allowed values";
        }

        $type = $schema['type'] ?? null;

        if ($type !== null && ! self::isType($data, $type)) {
            return "{$path} should be of type ".(is_array($type) ? implode('/', $type) : $type);
        }

        if (is_string($data)) {
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
                return "{$path} is too short";
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                return "{$path} is too long";
            }
        }

        if ((is_int($data) || is_float($data)) && ! is_bool($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                return "{$path} is below the minimum";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                return "{$path} is above the maximum";
            }
        }

        if (is_array($data) && array_is_list($data) && isset($schema['items'])) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                return "{$path} has too few items";
            }
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                return "{$path} has too many items";
            }
            foreach ($data as $i => $item) {
                if ($problem = self::problem($item, $schema['items'], "{$path}[{$i}]")) {
                    return $problem;
                }
            }
        }

        if (($type === 'object' || isset($schema['properties'])) && is_array($data)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $data)) {
                    return "{$path} is missing “{$key}”";
                }
            }

            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $data) && ($problem = self::problem($data[$key], $sub, "{$path}.{$key}"))) {
                    return $problem;
                }
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                $extra = array_diff(array_keys($data), array_keys($schema['properties'] ?? []));

                if ($extra !== []) {
                    return "{$path} has an unexpected field “".reset($extra).'”';
                }
            }
        }

        return null;
    }

    /** @param  string|list<string>  $type */
    private static function isType(mixed $data, string|array $type): bool
    {
        foreach ((array) $type as $t) {
            $ok = match ($t) {
                'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
                'array' => is_array($data) && ($data === [] || array_is_list($data)),
                'string' => is_string($data),
                'integer' => is_int($data),
                'number' => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null' => $data === null,
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
