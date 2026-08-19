<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A configured Group School cannot be read safely at this moment.
 *
 * This is deliberately distinct from request validation/authorization errors:
 * a consolidated report may disclose that one authorized School is incomplete,
 * but it must never turn a forged filter into a partial-looking result.
 */
class FinanceGroupTenantUnavailableException extends RuntimeException
{
}
