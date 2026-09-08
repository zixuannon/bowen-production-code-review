<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceCollectionHandoverBatch;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceUser;
use App\Services\CentralFinanceCollectionHandoverService;
use App\Services\CentralFinanceHeadFinanceHandoverConfirmService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CentralFinanceCollectionHandoverController extends Controller
{
    public function __construct(private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceCollectionHandoverService $handovers, private readonly CentralFinanceHeadFinanceHandoverConfirmService $confirmation) {}
    private function actor(): CentralFinanceUser { return $this->workspace->actor(Auth::user()); }
    public function index() { $actor=$this->actor(); $school=$this->workspace->requireOperatingSchool($actor); $batches=CentralFinanceCollectionHandoverBatch::on('mysql')->with('items')->where('school_id',$school->id)->latest()->paginate(20); return view('central-finance.collection-handovers.index', compact('school','batches')); }
    public function store(Request $request) { $actor=$this->actor(); $data=$request->validate(['payment_channel'=>['required','string'],'currency'=>['required','string','size:3'],'reference'=>['required','string','max:100'],'declared_handed_over_amount'=>['required','numeric','gt:0'],'idempotency_key'=>['required','string','max:100'],'note'=>['nullable','string','max:2000']]); $batch=$this->handovers->create($actor,$data['payment_channel'],$data['currency'],$data['reference'],$data['idempotency_key'],(string)$data['declared_handed_over_amount'],$data['note']??null); return back()->with('success',__('Handover batch created: :reference',['reference'=>$batch->reference])); }
    public function add(Request $request, CentralFinanceCollectionHandoverBatch $batch) { $data=$request->validate(['pending_collection_id'=>['required','integer']]); $this->handovers->add($this->actor(),$batch,(int)$data['pending_collection_id']); return back()->with('success',__('Collection added to handover.')); }
    public function submit(CentralFinanceCollectionHandoverBatch $batch) { $this->handovers->submit($this->actor(),$batch); return back()->with('success',__('Handover submitted for review.')); }
    public function hold(Request $request, CentralFinanceCollectionHandoverBatch $batch) { $data=$request->validate(['reason'=>['required','string','max:2000']]); $this->handovers->hold($this->actor(),$batch,$data['reason']); return back()->with('success',__('Handover placed on hold.')); }
    public function reject(Request $request, CentralFinanceCollectionHandoverBatch $batch) { $data=$request->validate(['reason'=>['required','string','max:2000']]); $this->handovers->reject($this->actor(),$batch,$data['reason']); return back()->with('success',__('Handover rejected.')); }
    public function cancel(Request $request, CentralFinanceCollectionHandoverBatch $batch) { $data=$request->validate(['reason'=>['required','string','max:2000']]); $this->handovers->cancel($this->actor(),$batch,$data['reason']); return back()->with('success',__('Handover cancelled.')); }
    public function confirm(Request $request, CentralFinanceCollectionHandoverBatch $batch) { $data=$request->validate(['fund_account_id'=>['required','integer'],'actual_received_amount'=>['required','numeric','gte:0'],'reason'=>['required','string','max:2000']]); $account=CentralFinanceFundAccount::on('mysql')->findOrFail($data['fund_account_id']); $this->confirmation->confirm($this->actor(),$batch->id,$account,(string)$data['actual_received_amount'],$data['reason']); return back()->with('success',__('Handover confirmed.')); }
}
