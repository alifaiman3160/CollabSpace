<?php

namespace App\Support\Formation;

use RuntimeException;

/**
 * Something stopped a set of groups being created, and nothing was saved.
 *
 * Its message is written for the lecturer and is shown to them as it stands, so
 * it names the situation rather than the mechanism. Thrown only from inside the
 * transaction that creates the groups, which rolls back before it propagates.
 */
class FormationException extends RuntimeException
{
}
