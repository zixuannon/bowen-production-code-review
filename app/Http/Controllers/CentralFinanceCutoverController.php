<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceDocumentAudit;
use App\Models\CentralFinanceSchoolCutover;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroupSchool;
use App\Models\School;
use App\Models\User;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceSchoolCutoverService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

final class CentralFinanceCutoverController extends Controller
{
    public function __construct(
        private readonly CentralFinanceSchoolCutoverService $cutovers,
        private readonly CentralFinanceConfigurationAuthorizationService $authorization,
        private readonly CentralFinanceWorkspaceService $workspace,
    ) {}

    public function show(Request $request): View
    {
        [$actor, $isSuperAdmin] = $this->actor();
        $schools = $this->authorizedSchools($actor, $isSuperAdmin);
        $school = $this->selectedSchool($request, $schools);
        $record = $school === null ? null : CentralFinanceSchoolCutover::on('mysql')->where('school_id', $school->id)->first();
        $effectiveAt = $record?->getRawOriginal('receivable_sync_effective_at');
        $audits = collect();
        $auditActors = collect();

        if ($record !== null) {
            $audits = CentralFinanceDocumentAudit::on('mysql')
                ->where('school_id', $school->id)
                ->where('document_type', 'central_finance_school_cutover')
                ->where('document_id', $record->id)
                ->latest()
                ->get();
            $auditActors = User::on('mysql')->whereIn('id', $audits->pluck('actor_id')->unique())->get()->keyBy('id');
        }

        return view('central-finance.cutover', [
            'schools' => $schools,
            'school' => $school,
            'record' => $record,
            'cutoverStatus' => $school === null ? null : $this->cutovers->statusForSchool((int) $school->id),
            'effectiveAt' => $effectiveAt === null ? null : CentralFinanceSchoolCutoverService::parseFreshStartBusinessTime((string) $effectiveAt),
            'timezone' => CentralFinanceSchoolCutoverService::FRESH_START_TIMEZONE,
            'audits' => $audits,
            'auditActors' => $auditActors,
            'hasCentralWrites' => $school !== null && $this->cutovers->hasRealCentralFinancialActivity((int) $school->id),
        ]);
    }

    public function updateCutoff(Request $request): RedirectResponse
    {
        [$actor, $isSuperAdmin] = $this->actor();
        $data = $request->validate([
            'school_id' => ['required', 'integer', 'min:1'],
            'receivable_sync_effective_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'receivable_sync_effective_reason' => ['required', 'string', 'max:2000'],
            'confirm_school_code' => ['required', 'string', 'max:64'],
            'confirmed' => ['accepted'],
        ]);
        $school = $this->authorizedSchool($this->authorizedSchools($actor, $isSuperAdmin), (int) $data['school_id']);
        $this->assertConfirmationCode($school, (string) $data['confirm_school_code']);

        try {
            $this->cutovers->setReceivableSyncEffectiveAt(
                $actor,
                $school,
                CentralFinanceSchoolCutoverService::parseReceivableSyncEffectiveAt((string) $data['receivable_sync_effective_at']),
                (string) $data['receivable_sync_effective_reason'],
            );
        } catch (LogicException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'receivable_sync_effective_at' => [__($exception->getMessage())],
            ]);
        }

        return redirect()->route('central-finance.cutover', ['school_id' => $school->id])
            ->with('success', __('Receivable cutoff saved with an append-only audit record.'));
    }

    public function updateStatus(Request $request): RedirectResponse
    {
        [$actor, $isSuperAdmin] = $this->actor();
        $data = $request->validate([
            'school_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in([
                CentralFinanceSchoolCutover::LEGACY,
                CentralFinanceSchoolCutover::READY,
                CentralFinanceSchoolCutover::CENTRAL,
            ])],
            'reason' => ['required', 'string', 'max:2000'],
            'confirm_school_code' => ['required', 'string', 'max:64'],
            'confirmed' => ['accepted'],
        ]);
        $school = $this->authorizedSchool($this->authorizedSchools($actor, $isSuperAdmin), (int) $data['school_id']);
        $this->assertConfirmationCode($school, (string) $data['confirm_school_code']);
        try {
            $this->cutovers->transition($actor, $school, (string) $data['status'], (string) $data['reason']);
        } catch (LogicException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['status' => [__($exception->getMessage())]]);
        }

        return redirect()->route('central-finance.cutover', ['school_id' => $school->id])
            ->with('success', __('Central Finance status updated with an append-only audit record.'));
    }

    /** @return array{0:CentralFinanceUser,1:bool} */
    private function actor(): array
    {
        $authenticated = Auth::user();
        abort_unless($authenticated, 403);
        $identity = User::on('mysql')->find($authenticated->id);
        abort_unless($identity && $identity->getRawOriginal('school_id') === null, 403);
        $isSuperAdmin = $identity->hasRole('Super Admin');
        abort_unless($isSuperAdmin || $identity->hasRole('Head Finance'), 403);

        return [CentralFinanceUser::on('mysql')->findOrFail($identity->id), $isSuperAdmin];
    }

    /** @return Collection<int, School> */
    private function authorizedSchools(CentralFinanceUser $actor, bool $isSuperAdmin): Collection
    {
        if ($isSuperAdmin) {
            $schoolIds = FinanceGroupSchool::query()
                ->where('status', 'active')
                ->whereHas('group', static fn ($query) => $query->where('status', 'active'))
                ->distinct()
                ->pluck('school_id');

            return School::on('mysql')->whereIn('id', $schoolIds)->orderBy('name')->get(['id', 'name', 'code']);
        }

        return $this->workspace->accessibleSchools($actor)
            ->filter(function (School $school) use ($actor): bool {
                try {
                    $this->authorization->assertCanConfigureCutover($actor, $school);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })->values();
    }

    private function selectedSchool(Request $request, Collection $schools): ?School
    {
        if (!$request->filled('school_id')) {
            return null;
        }

        return $this->authorizedSchool($schools, (int) $request->input('school_id'));
    }

    private function authorizedSchool(Collection $schools, int $schoolId): School
    {
        $school = $schools->firstWhere('id', $schoolId);
        abort_unless($school instanceof School, 403);

        return $school;
    }

    private function assertConfirmationCode(School $school, string $provided): void
    {
        if (strtoupper(trim($provided)) !== strtoupper((string) $school->code)) {
            throw ValidationException::withMessages([
                'confirm_school_code' => [__('Type the selected canonical School Code to confirm this controlled change.')],
            ]);
        }
    }
}
