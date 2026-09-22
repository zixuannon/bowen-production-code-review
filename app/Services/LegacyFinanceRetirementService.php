<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * Keeps the pre-Central-Finance tables available for historical reads while
 * making every former operating write an explicit, fail-closed retirement.
 */
final class LegacyFinanceRetirementService
{
    public function rejectWrite(string $operation): never
    {
        throw new GoneHttpException("Legacy Finance {$operation} is retired. Use Central Finance.");
    }
}
