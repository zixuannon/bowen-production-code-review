<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\User;
use App\Services\FinanceAccountAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BankAccountAssignmentController extends Controller
{
    public function update(Request $request, BankAccount $bankAccount, FinanceAccountAccessService $access)
    {
        abort_unless($access->canManageAccountAssignments(Auth::user()) && $bankAccount->school_id === Auth::user()->school_id, 403);
        $data = $request->validate(['user_ids' => ['array'], 'user_ids.*' => ['integer']]);
        $ids = User::where('school_id', Auth::user()->school_id)->whereIn('id', $data['user_ids'] ?? [])->pluck('id')->all();
        abort_unless(count($ids) === count($data['user_ids'] ?? []), 422, 'Assigned users must belong to this school.');
        $bankAccount->authorized_users()->sync($ids);
        return response()->json(['error' => false, 'message' => __('Fund Account access updated.')]);
    }
}
