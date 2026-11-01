<?php

namespace App\Support\Sections;

use RuntimeException;

/** An enrolment change that would leave a group holding somebody outside its section. Nothing was written. */
class EnrolmentException extends RuntimeException {}
