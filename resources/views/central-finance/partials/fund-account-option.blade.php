<option value="{{ $account->id }}"
    data-currency="{{ $account->currency }}"
    data-owner-type="{{ $account->owner_type }}"
    data-account-type="{{ $account->account_type }}"
    data-school-id="{{ $account->school_id }}"
    data-status="{{ $account->status }}">{{ app(\App\Services\CentralFinanceFundAccountDisplayService::class)->label($account, $schools ?? []) }}</option>
