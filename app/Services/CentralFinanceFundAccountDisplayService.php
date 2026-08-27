<?php

namespace App\Services;

use App\Models\CentralFinanceFundAccount;
use App\Models\School;

/** Human-safe labels for every Central Finance account selector and receipt. */
final class CentralFinanceFundAccountDisplayService
{
    /** @param iterable<School> $schools */
    public function label(CentralFinanceFundAccount $account, iterable $schools = []): string
    {
        $schoolNames = [];
        foreach ($schools as $school) {
            $schoolNames[(int) $school->id] = $school->name;
        }
        $owner = $account->owner_type === CentralFinanceFundAccount::OWNER_HQ
            ? 'HQ'
            : ($schoolNames[(int) $account->school_id] ?? 'School');
        $type = match ($account->account_type) {
            CentralFinanceFundAccount::TYPE_CASH => 'Cash',
            CentralFinanceFundAccount::TYPE_BANK => 'Bank',
            default => 'Other',
        };
        $parts = [sprintf('[%s · %s]', $owner, $type), $account->account_name, $account->account_code];
        if ($account->bank_name) {
            $parts[] = $account->bank_name;
        }
        if ($account->masked_account_identifier) {
            $parts[] = $account->masked_account_identifier;
        }
        $parts[] = $account->currency;
        return implode(' · ', array_filter($parts));
    }

    /** @return array{owner:string,type:string,status:string,label:string} */
    public function metadata(CentralFinanceFundAccount $account, iterable $schools = []): array
    {
        $label = $this->label($account, $schools);
        return [
            'owner' => $account->owner_type === CentralFinanceFundAccount::OWNER_HQ ? 'HQ' : 'School',
            'type' => $account->account_type,
            'status' => $account->status,
            'label' => $label,
        ];
    }
}
