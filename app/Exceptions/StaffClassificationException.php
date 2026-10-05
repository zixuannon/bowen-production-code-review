<?php

namespace App\Exceptions;

/** Must never be mistaken for a recoverable invitation-delivery failure. */
final class StaffClassificationException extends \RuntimeException {}
