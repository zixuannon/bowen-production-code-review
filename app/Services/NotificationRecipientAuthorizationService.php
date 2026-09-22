<?php

namespace App\Services;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves notification recipients from trusted server-side tenant context.
 *
 * The notification UI may filter its picker for convenience, but every
 * requested ID is treated as untrusted input here.  This check intentionally
 * happens before a Notification, UserNotification, queue job, email, or push
 * delivery is created.
 */
final class NotificationRecipientAuthorizationService
{
    /**
     * @param  array<int, mixed>  $requestedRoles
     * @return array{recipients: Collection<int, User>, roles: Collection<int, Role>}
     */
    public function authorize(User $actor, mixed $requestedRecipients, array $requestedRoles): array
    {
        $recipientIds = $this->ids($requestedRecipients, 'recipient');
        $roleIds = $this->ids($requestedRoles, 'role');

        /** @var Collection<int, Role> $roles */
        $roles = Role::withoutGlobalScopes()
            ->whereIn('id', $roleIds)
            ->where('name', '!=', 'School Admin')
            ->get(['id', 'name']);

        if ($roles->count() !== count($roleIds)) {
            throw new AuthorizationException('One or more notification roles are not authorized.');
        }

        /** @var Collection<int, User> $recipients */
        $recipients = User::query()
            ->with('roles:id,name')
            ->whereIn('id', $recipientIds)
            ->get()
            ->keyBy('id');

        if ($recipients->count() !== count($recipientIds)) {
            throw new AuthorizationException('One or more notification recipients are unavailable.');
        }

        $actorSchoolId = $this->trustedActorSchoolId($actor);
        $allowedRoleIds = $roles->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        foreach ($recipientIds as $recipientId) {
            /** @var User $recipient */
            $recipient = $recipients->get($recipientId);

            if ((int) $recipient->getRawOriginal('status') !== 1) {
                throw new AuthorizationException('Inactive notification recipients cannot be selected.');
            }

            $recipientRoleIds = $recipient->roles->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            if (array_intersect($allowedRoleIds, $recipientRoleIds) === []) {
                throw new AuthorizationException('A selected recipient does not have an authorized notification role.');
            }

            if ($actorSchoolId === null) {
                // Central/system actors retain the legacy system-only audience;
                // they are never silently treated as a School Admin for an
                // arbitrary tenant.
                if ($recipient->school_id !== null) {
                    throw new AuthorizationException('System notifications cannot target a School tenant without an authorized School context.');
                }

                continue;
            }

            if ($this->isGuardian($recipient)) {
                $belongsToActorSchool = $recipient->child()
                    ->where('school_id', $actorSchoolId)
                    ->exists();
            } else {
                $belongsToActorSchool = (int) $recipient->school_id === $actorSchoolId;
            }

            if (!$belongsToActorSchool) {
                throw new AuthorizationException('A notification recipient is outside the authenticated School scope.');
            }
        }

        return [
            'recipients' => $recipients->only($recipientIds)->values(),
            'roles' => $roles,
        ];
    }

    /** @return list<int> */
    private function ids(mixed $value, string $label): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value) || $value === []) {
            throw new AuthorizationException("At least one notification {$label} is required.");
        }

        $ids = [];
        foreach ($value as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
                throw new AuthorizationException("An invalid notification {$label} was supplied.");
            }
            $ids[] = (int) $id;
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw new AuthorizationException("At least one notification {$label} is required.");
        }

        return $ids;
    }

    private function trustedActorSchoolId(User $actor): ?int
    {
        if (!$actor->school_id) {
            return null;
        }

        // Match the Step 1 trusted-context rule used by Student Import V2:
        // the registry mapping is authoritative, while an empty retained
        // session key may fall back to the already-established school
        // connection.  Neither a request role nor a target recipient can
        // influence this identity check.
        $database = trim((string) session('school_database_name'));
        if ($database === '') {
            $database = trim((string) DB::connection('school')->getDatabaseName());
        }

        $schoolId = (int) $actor->school_id;
        if ($database === '' || !School::on('mysql')
            ->whereKey($schoolId)
            ->where('database_name', $database)
            ->exists()) {
            throw new AuthorizationException('The authenticated actor does not have a trusted School tenant context.');
        }

        return $schoolId;
    }

    private function isGuardian(User $recipient): bool
    {
        return $recipient->roles->contains(static fn ($role): bool => $role->name === 'Guardian');
    }
}
