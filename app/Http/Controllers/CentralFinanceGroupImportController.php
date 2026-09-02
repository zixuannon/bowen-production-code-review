<?php

namespace App\Http\Controllers;

use App\Exports\CentralFinanceGroupImportTemplateV2Export;
use App\Models\CentralFinanceGroupImportBatch;
use App\Models\CentralFinanceGroupImportPreviewRow;
use App\Models\FinanceGroup;
use App\Services\CentralFinanceGroupImportService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Group Finance Import V2: Preview followed by audited whole-file confirmation. */
final class CentralFinanceGroupImportController extends Controller
{
    public function __construct(private readonly CentralFinanceGroupImportService $imports, private readonly CentralFinanceWorkspaceService $workspace) {}

    public function workspace(Request $request): View
    {
        $actor = $this->actor();
        $groups = FinanceGroup::on('mysql')->where('status', 'active')->get()->filter(function (FinanceGroup $group) use ($actor): bool {
            $member = $group->users()->where('central_user_id', $actor->id)->where('status', 'active')->first();
            return $member && app(\App\Services\FinanceGroupScopeService::class)->accessibleSchools($member, 'operate_finance')->isNotEmpty();
        })->values();
        abort_if($groups->isEmpty(), 403);
        $batch = null;
        if ($request->filled('batch')) {
            $batch = CentralFinanceGroupImportBatch::on('mysql')->with('confirmedBy')->where('token', $request->string('batch')->toString())->where('uploaded_by', $actor->id)->firstOrFail();
            abort_unless($groups->pluck('id')->contains($batch->finance_group_id), 403);
            $batch->setRelation('rows', $batch->rows()->orderBy('row_number')->paginate(50)->withQueryString());
        }
        return view('central-finance.group-import.index', compact('groups', 'batch'));
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new CentralFinanceGroupImportTemplateV2Export(), 'group-finance-import-template-v2.xlsx');
    }

    public function preview(Request $request): RedirectResponse
    {
        $data = $request->validate(['finance_group_id' => ['required', 'integer'], 'group_import' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $batch = $this->imports->previewUploaded($this->actor(), FinanceGroup::on('mysql')->findOrFail((int) $data['finance_group_id']), $request->file('group_import'));
        return redirect()->route('central-finance.group-import.index', ['batch' => $batch->token])->with('success', __('Group Import preview completed. No financial document was created.'));
    }

    public function confirm(string $batch): RedirectResponse
    {
        $confirmed = $this->imports->confirm($this->actor(), $batch);
        return redirect()->route('central-finance.group-import.index', ['batch' => $confirmed->token])->with('success', __('Group Import confirmed.'));
    }

    /** Resolve a completed Group Import source through the normal trusted School context. */
    public function source(string $batch, int $row): RedirectResponse
    {
        $actor = $this->actor();
        $parent = CentralFinanceGroupImportBatch::on('mysql')
            ->where('token', $batch)->where('uploaded_by', $actor->id)->firstOrFail();
        $previewRow = CentralFinanceGroupImportPreviewRow::on('mysql')
            ->where('group_batch_id', $parent->id)->findOrFail($row);
        abort_unless($previewRow->canonical_source_id && in_array($previewRow->canonical_source_type, ['expense', 'other_income'], true), 404);
        $this->workspace->enterSchool($actor, (int) $previewRow->school_id);
        return redirect()->route($previewRow->canonical_source_type === 'expense' ? 'central-finance.expenses.show' : 'central-finance.other-income.show', $previewRow->canonical_source_id);
    }

    private function actor(): \App\Models\CentralFinanceUser
    {
        $user = Auth::user(); abort_unless($user, 403);
        return $this->workspace->actor($user);
    }
}
