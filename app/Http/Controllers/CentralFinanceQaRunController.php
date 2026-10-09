<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceQaRun;
use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceFundAccount;
use App\Models\CentralFinanceDataClassification;
use App\Models\CentralFinanceLedgerEntry;
use App\Models\CentralFinanceQaRunRecord;
use App\Models\CentralFinanceUnidentifiedDeposit;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceQaRunService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class CentralFinanceQaRunController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceDataIsolationService $isolation,
        private readonly CentralFinanceQaRunService $runs,
    ) {}

    public function index(): View
    {
        $actor = $this->manager();
        $schoolId = $this->runs->permanentQaSchoolId();
        abort_unless($schoolId && $this->workspace->accessibleSchools($actor, true)->contains('id', $schoolId), 404);
        $runList = $this->runs->runsForSchool($schoolId);

        return view('central-finance.qa-runs.index', [
            'school' => $this->workspace->assertCanViewSchool($actor, $schoolId),
            'runs' => $runList,
            'activeRun' => $runList->first(fn (CentralFinanceQaRun $run) => $run->status === CentralFinanceQaRun::ACTIVE),
        ]);
    }

    public function show(int $run): View
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->with('records')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);
        $auditIds = $record->records->where('subject_type', 'audit')->pluck('subject_id')->filter()->all();
        $auditEntries = $auditIds === [] ? collect() : CentralFinanceDocumentAudit::on('mysql')->where('school_id', $record->school_id)->whereIn('id', $auditIds)->orderByDesc('id')->get();
        $depositIds = $record->records->where('school_id', (int) $record->school_id)->where('subject_scope', 'central')->where('subject_type', 'unidentified_deposit')->pluck('subject_id')->filter()->all();
        $unidentifiedDeposits = $depositIds === [] ? collect() : CentralFinanceUnidentifiedDeposit::on('mysql')
            ->with('fundAccount')
            ->whereIn('id', $depositIds)
            ->orderByDesc('id')
            ->get();
        return view('central-finance.qa-runs.show', [
            'run' => $record,
            'auditEntries' => $auditEntries,
            'unidentifiedDeposits' => $unidentifiedDeposits,
            'hasUnidentifiedDeposits' => $unidentifiedDeposits->isNotEmpty(),
        ]);
    }

    /**
     * Read a ledger entry only through its immutable QA Run membership.
     * Group-owned cash entries have no School ID and must not be made visible
     * through the general School or Official Ledger scope.
     */
    public function ledgerDetail(int $run, int $ledger): View
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);

        CentralFinanceQaRunRecord::on('mysql')->where([
            'qa_run_id' => $record->id,
            'school_id' => $record->school_id,
            'subject_scope' => 'central',
            'subject_type' => 'ledger',
            'subject_id' => $ledger,
        ])->firstOrFail();

        $entry = CentralFinanceLedgerEntry::on('mysql')->with('fundAccount')->findOrFail($ledger);
        abort_unless($this->isolation->classification('ledger', $entry->id) === CentralFinanceDataClassification::QA_TEST, 404);
        abort_unless($entry->school_id === null || (int) $entry->school_id === (int) $record->school_id, 404);

        $account = $entry->fundAccount;
        abort_unless($account !== null, 404);
        abort_unless($this->workspace->readableAccounts($actor, (int) $record->school_id, true)->contains('id', (int) $account->id), 404);
        if ($entry->school_id === null) {
            abort_unless($account->owner_type === CentralFinanceFundAccount::OWNER_HQ && $account->school_id === null, 404);
        }

        return view('central-finance.qa-runs.ledger-detail', [
            'run' => $record,
            'entry' => $entry,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->manager();
        $schoolId = $this->runs->permanentQaSchoolId();
        abort_unless($schoolId, 404);
        $data = $request->validate(['label' => ['required', 'string', 'max:191']]);
        $run = $this->runs->create($actor, $schoolId, $data['label']);
        return redirect()->route('central-finance.qa-runs.show', $run->id)->with('success', 'QA Run created. Import fresh QA Students with Student Import V2 before activation.');
    }

    public function activate(int $run): RedirectResponse
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);
        $this->runs->activate($actor, $record->id);
        return back()->with('success', 'QA Run activated.');
    }

    public function complete(int $run): RedirectResponse
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);
        $this->runs->complete($actor, $record->id);
        return back()->with('success', 'QA Run completed. New Finance writes are closed.');
    }

    public function archive(Request $request, int $run): RedirectResponse
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $this->runs->archive($actor, $record->id, $data['reason']);
        return back()->with('success', 'QA Run archived. Finance history remains available.');
    }

    public function reconcile(int $run): RedirectResponse
    {
        $actor = $this->manager();
        $record = CentralFinanceQaRun::on('mysql')->findOrFail($run);
        $this->assertQaRunVisible($actor, $record);
        $count = $this->runs->reconcilePreparingRun($actor, $record->id);
        return back()->with('success', "Reconciled {$count} Student Import V2 identity record(s).");
    }

    private function manager()
    {
        $actor = $this->workspace->actor(Auth::user());
        abort_unless($this->isolation->canIncludeQaTest($actor), 403, 'Only Head Finance or Super Admin can manage QA Runs.');
        return $actor;
    }

    private function assertQaRunVisible($actor, CentralFinanceQaRun $run): void
    {
        abort_unless($this->runs->isPermanentQaSchool((int) $run->school_id), 404);
        abort_unless($this->workspace->accessibleSchools($actor, true)->contains('id', (int) $run->school_id), 404);
    }
}
