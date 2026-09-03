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
        $groups = $this->imports->authorizedGroups($actor);
        abort_if($groups->isEmpty(), 403);
        $batch = null;
        if ($request->filled('batch')) {
            $batch = CentralFinanceGroupImportBatch::on('mysql')->with('confirmedBy')->where('token', $request->string('batch')->toString())->where('uploaded_by', $actor->id)->firstOrFail();
            abort_unless($groups->pluck('id')->contains($batch->finance_group_id), 403);
            $batch->setRelation('rows', $batch->rows()->orderBy('row_number')->paginate(50)->withQueryString());
        }
        return view('central-finance.group-import.index', compact('groups', 'batch'));
    }

    public function template(Request $request): BinaryFileResponse
    {
        $actor = $this->actor();
        $groups = $this->imports->authorizedGroups($actor);
        abort_if($groups->isEmpty(), 403);

        $requestedGroupId = $request->integer('finance_group_id');
        if ($requestedGroupId > 0) {
            $group = $groups->firstWhere('id', $requestedGroupId);
            abort_if($group === null, 403);
        } else {
            abort_unless($groups->count() === 1, 422, 'Choose a Finance Group before downloading its lookup template.');
            $group = $groups->sole();
        }

        $lookups = $this->imports->templateLookups($actor, $group);

        return Excel::download(
            new CentralFinanceGroupImportTemplateV2Export($lookups['schools'], $lookups['accounts'], $lookups['categories']),
            'group-finance-import-template-v2.1.xlsx',
        );
    }

    public function preview(Request $request): RedirectResponse
    {
        $data = $request->validate(['finance_group_id' => ['required', 'integer'], 'group_import' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $actor = $this->actor();
        $group = FinanceGroup::on('mysql')->findOrFail((int) $data['finance_group_id']);
        $this->imports->assertCanOperateGroup($actor, $group);
        $batch = $this->imports->previewUploaded($actor, $group, $request->file('group_import'));
        return redirect()->route('central-finance.group-import.index', ['batch' => $batch->token])->with('success', __('Group Import preview completed. No financial document was created.'));
    }

    public function confirm(string $batch): RedirectResponse
    {
        $actor = $this->actor();
        $preview = CentralFinanceGroupImportBatch::on('mysql')->where('token', $batch)->firstOrFail();
        $this->imports->assertCanOperateGroup($actor, FinanceGroup::on('mysql')->findOrFail($preview->finance_group_id));
        abort_unless((int) $preview->uploaded_by === (int) $actor->id, 403);
        $confirmed = $this->imports->confirm($actor, $batch);
        return redirect()->route('central-finance.group-import.index', ['batch' => $confirmed->token])->with('success', __('Group Import confirmed.'));
    }

    /** Resolve a completed Group Import source through the normal trusted School context. */
    public function source(string $batch, int $row): RedirectResponse
    {
        $actor = $this->actor();
        abort_if($this->imports->authorizedGroups($actor)->isEmpty(), 403);
        $parent = CentralFinanceGroupImportBatch::on('mysql')
            ->where('token', $batch)->where('uploaded_by', $actor->id)->firstOrFail();
        $this->imports->assertCanOperateGroup($actor, FinanceGroup::on('mysql')->findOrFail($parent->finance_group_id));
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
