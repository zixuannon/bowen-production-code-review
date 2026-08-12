<?php
namespace App\Http\Controllers;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\FinanceAccountAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FinanceStaffController extends Controller {
    public function index() {
        $actor=Auth::user(); abort_unless($actor->hasAnyRole(['School Admin','Head Finance']),403);
        $users=User::where('school_id',$actor->school_id)->with(['authorized_bank_accounts:id,account_name'])->get();
        $accounts=BankAccount::where('school_id',$actor->school_id)->active()->get(['id','account_name']);
        return view('finance-staff.index',compact('users','accounts','actor'));
    }
    public function role(Request $request, User $user) {
        $actor=Auth::user(); abort_unless($actor->hasRole('School Admin') && $user->school_id===$actor->school_id,403);
        $data=$request->validate(['role'=>['required','in:Head Finance,Cashier'],'action'=>['required','in:assign,remove']]);
        if($data['action']==='assign') $user->assignRole($data['role']); else { $user->removeRole($data['role']); if($data['role']==='Cashier') $user->authorized_bank_accounts()->detach(); }
        return response()->json(['error'=>false]);
    }
    public function accounts(Request $request, User $user, FinanceAccountAccessService $access) {
        $actor=Auth::user(); abort_unless($access->canManageAccountAssignments($actor) && $user->school_id===$actor->school_id && $user->hasRole('Cashier'),403);
        $data=$request->validate(['account_ids'=>['array'],'account_ids.*'=>['integer']]);
        $ids=BankAccount::where('school_id',$actor->school_id)->active()->whereIn('id',$data['account_ids']??[])->pluck('id')->all();
        abort_unless(count($ids)===count($data['account_ids']??[]),422);
        $user->authorized_bank_accounts()->sync($ids); return response()->json(['error'=>false]);
    }
}
