<?php
namespace App\Http\Controllers;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\FinanceAccountAccessService;
use App\Services\FinanceAuthorizationService;
use App\Services\TenantPasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceStaffController extends Controller {
    public function index() {
        $actor=Auth::user(); app(FinanceAuthorizationService::class)->assert($actor, 'finance-staff-manage'); abort_unless($actor->hasAnyRole(['School Admin','Head Finance']),403);
        $users=User::where('school_id',$actor->school_id)->with(['authorized_bank_accounts:id,account_name'])->get();
        $accounts=BankAccount::where('school_id',$actor->school_id)->active()->get(['id','account_name']);
        return view('finance-staff.index',compact('users','accounts','actor'));
    }
    public function role(Request $request, User $user) {
        $actor=Auth::user(); app(FinanceAuthorizationService::class)->assert($actor, 'finance-staff-manage'); abort_unless($actor->hasRole('School Admin') && $user->school_id===$actor->school_id,403);
        $data=$request->validate(['role'=>['required','in:Head Finance,Cashier'],'action'=>['required','in:assign,remove']]);
        if($data['action']==='assign') $user->assignRole($data['role']); else { $user->removeRole($data['role']); if($data['role']==='Cashier') $user->authorized_bank_accounts()->detach(); }
        return response()->json(['error'=>false]);
    }
    public function store(Request $request) {
        $actor=Auth::user(); app(FinanceAuthorizationService::class)->assert($actor, 'finance-staff-manage'); abort_unless($actor->hasAnyRole(['School Admin','Head Finance']),403);
        $data=$request->validate([
            'first_name'=>['required','string','max:255'], 'last_name'=>['required','string','max:255'],
            'email'=>['required','email','max:255','unique:users,email'],
            'mobile'=>['nullable','string','max:30','unique:users,mobile'],
            'account_ids'=>['nullable','array'], 'account_ids.*'=>['integer'],
        ]);
        $ids=BankAccount::where('school_id',$actor->school_id)->active()->whereIn('id',$data['account_ids']??[])->pluck('id')->all();
        abort_unless(count($ids)===count($data['account_ids']??[]),422);

        $staff=DB::transaction(function () use ($actor,$data,$ids) {
            // The random value is hashed immediately and never returned. The
            // supported Password Broker flow below is the only credential setup.
            $staff=User::create([
                'first_name'=>$data['first_name'], 'last_name'=>$data['last_name'], 'email'=>$data['email'],
                'mobile'=>$data['mobile']??null, 'password'=>Hash::make(Str::random(64)),
                'school_id'=>$actor->school_id, 'status'=>1, 'two_factor_enabled'=>0,
            ]);
            $staff->assignRole('Cashier');
            $staff->authorized_bank_accounts()->sync($ids);
            return $staff;
        });
        try {
            $response=app(TenantPasswordBroker::class)->broker()->sendResetLink(['email'=>$staff->email]);
            if($response!==Password::RESET_LINK_SENT) throw ValidationException::withMessages(['email'=>[trans($response)]]);
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($staff) { $staff->authorized_bank_accounts()->detach(); $staff->syncRoles([]); $staff->forceDelete(); });
            throw $exception;
        }
        return response()->json(['error'=>false,'id'=>$staff->id,'message'=>__('Accountant created. A secure password setup link was sent to the email address.')]);
    }
    public function accounts(Request $request, User $user, FinanceAccountAccessService $access) {
        $actor=Auth::user(); app(FinanceAuthorizationService::class)->assert($actor, 'finance-staff-manage'); abort_unless($access->canManageAccountAssignments($actor) && $user->school_id===$actor->school_id && $user->hasRole('Cashier'),403);
        $data=$request->validate(['account_ids'=>['array'],'account_ids.*'=>['integer']]);
        $ids=BankAccount::where('school_id',$actor->school_id)->active()->whereIn('id',$data['account_ids']??[])->pluck('id')->all();
        abort_unless(count($ids)===count($data['account_ids']??[]),422);
        $user->authorized_bank_accounts()->sync($ids); return response()->json(['error'=>false]);
    }
}
