<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when file upload fails security validation.
 *
 * The global exception handler converts this into a safe
 * 422 JSON validation response — never a 500 with a stack trace.
 */
class UploadValidationException extends RuntimeException
{
    /**
     * @param  string  $message  Human-readable reason (not exposed to client by default)
     */
    public function __construct(string $message = 'File upload failed validation.')
    {
        parent::__construct($message);
    }
}
