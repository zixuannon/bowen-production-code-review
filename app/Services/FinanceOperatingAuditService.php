<?php

namespace App\Services;

use App\Models\FinanceOperatingAudit;
use App\ValueObjects\FinanceOperatingContext;

class FinanceOperatingAuditService
{
    public function record(FinanceOperatingContext $context, string $sourceType, int $sourceId, string $action): FinanceOperatingAudit
    {
        return FinanceOperatingAudit::query()->create([
            'central_actor_id' => $context->centralActorId,
            'finance_group_id' => $context->groupId,
            'school_id' => $context->schoolId,
            'tenant_user_id' => $context->tenantUserId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'action' => $action,
            'request_source' => 'finance_operating_context',
        ]);
    }
}
