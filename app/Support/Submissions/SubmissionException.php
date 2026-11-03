<?php

namespace App\Support\Submissions;

use RuntimeException;

/** A submission that cannot be made or withdrawn; the message is safe to show to the student. */
class SubmissionException extends RuntimeException {}
