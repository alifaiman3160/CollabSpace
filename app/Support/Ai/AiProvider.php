<?php

namespace App\Support\Ai;

/**
 * The only thing the rest of the application knows about an AI provider.
 *
 * Give it instructions, some data and the shape the answer must take; get back
 * that answer, already checked against the shape. Which vendor is behind it,
 * how it is called and how it is told to answer in JSON are the driver's
 * business (App\Support\Ai\Drivers) and go no further.
 *
 * The contract on both sides:
 *
 *  - $system is the caller's instructions. The driver adds its own standing
 *    notice that the payload is data, never instructions.
 *  - $payload is untrusted. Pass an array (encoded as JSON) or a string.
 *  - $schema is a JSON Schema for the answer. The result has been validated
 *    against it, so a caller can rely on the shape, but should still check
 *    meaning (is this id one I offered?) because a schema cannot.
 *
 * @throws AiException  when the provider cannot be reached, refuses, or answers
 *                      with something that does not fit the schema
 */
interface AiProvider
{
    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, mixed>  $schema  a JSON Schema whose root is an object
     * @return array<string, mixed>
     */
    public function complete(string $system, array|string $payload, array $schema): array;
}
