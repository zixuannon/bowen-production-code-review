<?php

namespace App\Http\Controllers;

use App\Models\CentralFinanceDataClassification;
use App\Services\CentralFinanceConfigurationAuthorizationService;
use App\Services\CentralFinanceDataIsolationService;
use App\Services\CentralFinanceWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Audited control-plane adapter; classification never edits a Finance row. */
final class CentralFinanceDataClassificationController extends Controller
{
    public function __construct(
        private readonly CentralFinanceWorkspaceService $workspace,
        private readonly CentralFinanceConfigurationAuthorizationService $configuration,
        private readonly CentralFinanceDataIsolationService $isolation,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $actor = $this->workspace->actor(Auth::user());
        $data = $request->validate([
            'school_id' => ['required', 'integer'],
            'subject_type' => ['required', 'string', 'max:64'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'classification' => ['required', Rule::in(CentralFinanceDataClassification::VALUES)],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $school = $this->workspace->assertCanViewSchool($actor, (int) $data['school_id']);
        $this->configuration->assertCanConfigureCutover($actor, $school);
        $this->isolation->classify(
            $actor,
            (int) $school->id,
            (string) $data['subject_type'],
            (int) $data['subject_id'],
            (string) $data['classification'],
            (string) $data['reason'],
        );

        return back()->with('success', __('Data classification updated; financial history was not changed.'));
    }
}
