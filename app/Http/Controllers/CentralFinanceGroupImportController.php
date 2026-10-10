<?php

namespace App\Http\Controllers;

use App\Exports\CentralFinanceGroupImportTemplateV3Export;
use App\Models\CentralFinanceGroupImportBatch;
use App\Models\CentralFinanceGroupImportPreviewRow;
use App\Models\FinanceGroup;
use App\Services\CentralFinanceGroupImportService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Group Finance Import V2: Preview followed by audited whole-file confirmation. */
final class CentralFinanceGroupImportController extends Controller
{
    public function __construct(private readonly CentralFinanceGroupImportService $imports, private readonly CentralFinanceWorkspaceService $workspace, private readonly CentralFinanceDataIsolationService $dataIsolation) {}

    public function workspace(Request $request): View
    {
        $actor = $this->actor();
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
        $canIncludeQaTest = $this->dataIsolation->canIncludeQaTest($actor);
        $groups = $this->imports->authorizedGroups($actor);
        abort_if($groups->isEmpty(), 403);
        $batch = null;
        if ($request->filled('batch')) {
            $batchQuery = CentralFinanceGroupImportBatch::on('mysql')->with('confirmedBy')->where('token', $request->string('batch')->toString())->where('uploaded_by', $actor->id);
            $this->dataIsolation->apply($batchQuery, 'group_import_batch', $includeQaTest);
            $batch = $batchQuery->firstOrFail();
            abort_unless($groups->pluck('id')->contains($batch->finance_group_id), 403);
            $batch->setAttribute('production_eligible', $this->dataIsolation->isProduction('group_import_batch', (int) $batch->id));
            $batch->setRelation('rows', $batch->rows()->orderBy('row_number')->paginate(50)->withQueryString());
        }
        return view('central-finance.group-import.index', compact('groups', 'batch', 'includeQaTest', 'canIncludeQaTest'));
    }

    /** A read-only history of batches uploaded by this operator in authorized groups. */
    public function history(Request $request): View
    {
        $actor = $this->actor();
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor);
        $groups = $this->imports->authorizedGroups($actor);
        abort_if($groups->isEmpty(), 403);

        $groupIds = $groups->modelKeys();
        $groupId = $request->integer('finance_group_id');
        abort_if($groupId > 0 && !in_array($groupId, $groupIds, true), 403);

        $query = CentralFinanceGroupImportBatch::on('mysql')
            ->with('confirmedBy')
            ->where('uploaded_by', $actor->id)
            ->whereIn('finance_group_id', $groupId > 0 ? [$groupId] : $groupIds)
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->string('status')->toString()));
        if ($request->filled('status') && !in_array($request->string('status')->toString(), ['previewed', 'completed', 'failed'], true)) {
            abort(422, 'Invalid Group Import status filter.');
        }
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where('file_name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%');
            }
        }
        $this->dataIsolation->apply($query, 'group_import_batch', $includeQaTest);
        $history = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('central-finance.group-import.history', compact('groups', 'history', 'includeQaTest'));
    }

    /** Export only validation errors/conflicts for a batch owned by this authorized operator. */
    public function exportErrors(Request $request, string $batch): StreamedResponse
    {
        $actor = $this->actor();
        $includeQaTest = $this->dataIsolation->includeQaTest($request, $actor)
            || $this->workspace->isQaTestSchoolContext($actor);
        $authorizedGroups = $this->imports->authorizedGroups($actor);
        abort_if($authorizedGroups->isEmpty(), 403);

        $batchQuery = CentralFinanceGroupImportBatch::on('mysql')
            ->where('token', $batch)
            ->where('uploaded_by', $actor->id);
        $this->dataIsolation->apply($batchQuery, 'group_import_batch', $includeQaTest);
        $import = $batchQuery->firstOrFail();
        abort_unless($authorizedGroups->pluck('id')->contains((int) $import->finance_group_id), 403);

        $rows = CentralFinanceGroupImportPreviewRow::on('mysql')
            ->where('group_batch_id', $import->id)
            ->whereIn('result_status', ['Error', 'Conflict'])
            ->orderBy('row_number')
            ->get();
        $filename = 'group-import-errors-'.$import->token.'.csv';

        return response()->streamDownload(static function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Row', 'Status', 'Error Code', 'Validation Result', 'School Code', 'School', 'Document Type', 'Transaction Date', 'Claimant', 'Description', 'Account Code', 'Fund Account Code', 'Payment Method', 'Income', 'Expense', 'Currency', 'Reference', 'Remarks']);
            foreach ($rows as $row) {
                $data = (array) $row->normalized_data;
                fputcsv($output, array_map(self::csvCell(...), [
                    $row->row_number, $row->result_status, $row->error_code, $row->error_message,
                    $data['school_code'] ?? '', $data['school_label'] ?? '', $data['document_type'] ?? $row->document_type,
                    $data['transaction_date'] ?? '', $data['claimant'] ?? '', $data['summary'] ?? '',
                    $data['category_code'] ?? '', $data['fund_account_code'] ?? '', $data['payment_method'] ?? '',
                    $data['income'] ?? '', $data['expense'] ?? '', $data['currency'] ?? '',
                    $data['reference_no'] ?? $row->reference_no ?? '', $data['remarks'] ?? '',
                ]));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            new CentralFinanceGroupImportTemplateV3Export($lookups['schools'], $lookups['accounts'], $lookups['categories']),
            'group-finance-import-template-v3.xlsx',
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
        abort_unless($previewRow->canonical_source_id && in_array($previewRow->canonical_source_type, ['expense', 'other_income', 'unidentified_deposit'], true), 404);
        if ($previewRow->canonical_source_type === 'unidentified_deposit') {
            return redirect()->route('central-finance.unidentified-deposits.index', ['deposit' => $previewRow->canonical_source_uuid]);
        }
        $this->workspace->enterSchool($actor, (int) $previewRow->school_id);
        return redirect()->route($previewRow->canonical_source_type === 'expense' ? 'central-finance.expenses.show' : 'central-finance.other-income.show', $previewRow->canonical_source_id);
    }

    private function actor(): \App\Models\CentralFinanceUser
    {
        $user = Auth::user(); abort_unless($user, 403);
        return $this->workspace->actor($user);
    }

    private static function csvCell(mixed $value): string|int|float
    {
        if (!is_string($value)) return $value ?? '';
        // Keep spreadsheet clients from treating imported text as a formula.
        return preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }
}
