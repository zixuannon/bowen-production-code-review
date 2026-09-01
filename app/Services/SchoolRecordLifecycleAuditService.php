<?php

namespace App\Services;

use App\Models\SchoolRecordLifecycleAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes tenant-local lifecycle evidence without changing Finance audit data. */
final class SchoolRecordLifecycleAuditService
{
    /** @param array<string,mixed> $metadata */
    public function record(User $actor, Model $subject, string $action, string $reason, array $metadata = []): SchoolRecordLifecycleAudit
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [__('A lifecycle reason is required.')]]);
        }
        if (!in_array($action, [
            SchoolRecordLifecycleAudit::DEACTIVATE,
            SchoolRecordLifecycleAudit::REACTIVATE,
            SchoolRecordLifecycleAudit::WITHDRAW,
            SchoolRecordLifecycleAudit::ARCHIVE,
        ], true)) {
            throw ValidationException::withMessages(['action' => [__('The lifecycle action is invalid.')]]);
        }

        return DB::connection('school')->transaction(function () use ($actor, $subject, $action, $reason, $metadata): SchoolRecordLifecycleAudit {
            return SchoolRecordLifecycleAudit::on('school')->create([
                'subject_type' => $this->subjectType($subject),
                'subject_id' => (int) $subject->getKey(),
                'subject_uuid' => $this->uuidFrom($subject),
                'action' => $action,
                'reason' => $reason,
                'actor_user_id' => (int) $actor->getKey(),
                'actor_user_uuid' => $this->uuidFrom($actor),
                'metadata' => $metadata ?: null,
                'created_at' => now(),
            ]);
        });
    }

    private function subjectType(Model $subject): string
    {
        return match ($subject::class) {
            User::class => $subject->hasRole('Teacher') ? 'teacher' : 'student',
            \App\Models\Fee::class => 'fee',
            \App\Models\FeesType::class => 'fees_type',
            \App\Models\FeesClassType::class => 'fees_class_type',
            default => throw ValidationException::withMessages(['subject' => [__('This record type does not support lifecycle auditing.')]]),
        };
    }

    private function uuidFrom(Model $model): ?string
    {
        foreach (['central_finance_source_uuid', 'uuid'] as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && $value !== '') return $value;
        }
        return null;
    }
}
