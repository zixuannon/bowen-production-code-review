<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when file upload fails security validation.
 *
 * The global exception handler converts this into:
 * - A 422 JSON response for API requests (expects JSON).
 * - A redirect back with validation errors for browser form requests.
 *
 * No stack trace or server path is ever exposed to the client.
 */
class UploadValidationException extends RuntimeException
{
    /**
     * Upload field name for validation error key (e.g. 'image', 'file', 'vertical_logo').
     *
     * @var string
     */
    protected string $field;

    /**
     * @param  string  $message  Human-readable reason (used for server-side logging only)
     * @param  string  $field    Upload field name for the validation error bag (default: 'file')
     */
    public function __construct(string $message = 'File upload failed validation.', string $field = 'file')
    {
        parent::__construct($message);
        $this->field = $field;
    }

    /**
     * Get the upload field name for the validation error.
     */
    public function getField(): string
    {
        return $this->field;
    }
}
