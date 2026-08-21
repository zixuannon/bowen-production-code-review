<?php

namespace App\Services;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceUser;
use Illuminate\Database\Eloquent\Model;

final class CentralFinanceDocumentAuditService
{
    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    public function record(CentralFinanceUser $actor, Model $document, string $type, string $action, ?string $reason = null, ?array $before = null, ?array $after = null): CentralFinanceDocumentAudit
    {
        return CentralFinanceDocumentAudit::on('mysql')->create([
            'school_id' => $document->school_id,
            'document_type' => $type,
            'document_id' => $document->id,
            'action' => $action,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
        ]);
    }
}
