<?php
namespace App\Services;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinancePayment;
use App\Models\CentralFinanceReceipt;
use App\Models\CentralFinanceReceivable;
use App\Models\CentralFinanceUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CentralFinancePaymentService {
    public function __construct(private readonly CentralFinanceSchoolScopeService $schools, private readonly CentralFinanceFundAccountScopeService $accounts, private readonly CentralFinanceLedgerService $ledger) {}
    /** @return array{payment:CentralFinancePayment,receipt:CentralFinanceReceipt} */
    public function collect(CentralFinanceUser $actor,int $receivableId,CentralFinanceFundAccount $account,float $amount,string $method,CarbonImmutable $paidAt,string $idempotencyReference,?string $paymentReference=null): array {
        if ($amount<=0 || !is_finite($amount) || !preg_match('/^[A-Za-z0-9 _.-]{2,40}$/',$method) || !preg_match('/^[A-Za-z0-9_.:-]{2,100}$/',$idempotencyReference)) throw new InvalidArgumentException('Central payment input is invalid.');
        return DB::connection('mysql')->transaction(function() use($actor,$receivableId,$account,$amount,$method,$paidAt,$idempotencyReference,$paymentReference): array {
            $r=CentralFinanceReceivable::on('mysql')->lockForUpdate()->findOrFail($receivableId);
            $this->schools->assertCanOperate($actor,$r->school_id); $this->accounts->assertCanOperate($actor,$account);
            $key=hash('sha256',$r->school_id.'|'.$r->id.'|'.$idempotencyReference);
            $existing=CentralFinancePayment::on('mysql')->where('idempotency_key',$key)->lockForUpdate()->first();
            if ($existing) return ['payment'=>$existing,'receipt'=>CentralFinanceReceipt::on('mysql')->where('payment_id',$existing->id)->firstOrFail()];
            $account=CentralFinanceFundAccount::on('mysql')->active()->lockForUpdate()->findOrFail($account->id);
            if (strtoupper($account->currency)!==strtoupper($r->currency) || (float)$r->amount_paid+$amount>(float)$r->amount_due) throw new InvalidArgumentException('Central payment exceeds the receivable or currency/account scope.');
            if ($paymentReference && CentralFinancePayment::on('mysql')->where(['school_id'=>$r->school_id,'payment_reference'=>$paymentReference])->exists()) throw new InvalidArgumentException('Payment reference is already used for this School.');
            $payment=CentralFinancePayment::on('mysql')->create(['payment_uuid'=>(string)Str::uuid(),'school_id'=>$r->school_id,'receivable_id'=>$r->id,'fund_account_id'=>$account->id,'idempotency_key'=>$key,'payment_reference'=>$paymentReference,'payment_method'=>$method,'currency'=>strtoupper($r->currency),'amount'=>$amount,'paid_at'=>$paidAt,'received_by'=>$actor->id]);
            $receipt=CentralFinanceReceipt::on('mysql')->create(['receipt_uuid'=>(string)Str::uuid(),'school_id'=>$r->school_id,'payment_id'=>$payment->id,'receipt_no'=>'CFR-'.$r->school_id.'-'.strtoupper(substr(str_replace('-','',$payment->payment_uuid),0,12)),'issued_at'=>$paidAt,'issued_by'=>$actor->id]);
            $this->ledger->recordOperatingIncome($actor,$account,$r->school_id,'central_payment',$payment->payment_uuid,$amount,$paidAt,$receipt->receipt_no);
            $paid=(float)$r->amount_paid+$amount; $r->update(['amount_paid'=>$paid,'status'=>$paid>=(float)$r->amount_due?CentralFinanceReceivable::PAID:CentralFinanceReceivable::PARTIAL]);
            return ['payment'=>$payment,'receipt'=>$receipt];
        });
    }
}
