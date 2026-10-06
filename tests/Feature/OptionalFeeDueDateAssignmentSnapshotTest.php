<?php

namespace Tests\Feature;

use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\Students;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Models\User;
use App\Services\StudentFeeAssignmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalFeeDueDateAssignmentSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $tenantDbAsDefault = true;
    protected $connectionsToTransact = ['mysql', 'school'];

    public function test_null_and_dated_fee_dates_are_frozen_in_confirmed_assignment_snapshots(): void
    {
        $section = DB::table('class_sections')->where('school_id', 1)->first();
        $this->assertNotNull($section);
        $guardianId = (int) DB::table('users')->where('school_id', 1)->value('id');
        $this->assertGreaterThan(0, $guardianId);

        $studentUserId = DB::table('users')->insertGetId([
            'first_name' => 'Due Date', 'last_name' => 'Snapshot Student',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('local-only'),
            'school_id' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $student = Students::query()->create([
            'user_id' => $studentUserId, 'class_section_id' => $section->id,
            'admission_no' => 'DUE-'.Str::upper(Str::random(8)), 'admission_date' => now()->toDateString(),
            'guardian_id' => $guardianId, 'school_id' => 1, 'session_year_id' => 1,
        ]);
        $actor = User::query()->create([
            'first_name' => 'Due Date', 'last_name' => 'Snapshot Actor',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('local-only'),
            'school_id' => 1, 'status' => 1,
        ]);

        $service = $this->app->make(StudentFeeAssignmentService::class);

        foreach ([null, '2026-11-30'] as $originalDate) {
            $feeTypeId = DB::table('fees_types')->insertGetId([
                'name' => 'Snapshot '.Str::random(6), 'school_id' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $fee = new Fee();
            $fee->forceFill([
                'name' => 'Snapshot Fee '.Str::random(6), 'due_date' => $originalDate,
                'due_charges' => 0, 'due_charges_amount' => 0,
                'class_id' => $section->class_id, 'school_id' => 1, 'session_year_id' => 1,
            ]);
            $fee->save();
            $template = FeesClassType::query()->create([
                'class_id' => $section->class_id, 'fees_id' => $fee->id, 'fees_type_id' => $feeTypeId,
                'amount' => 500, 'optional' => false, 'school_id' => 1,
                'fee_currency' => 'MMK', 'fee_original_amount' => 500,
                'fee_exchange_rate_snapshot' => 1, 'fee_amount_mmk' => 500,
            ]);

            // Save Draft is the Fee Setup preview snapshot; confirmation freezes it.
            $draft = $service->saveDraft($student, $actor, []);
            $item = $draft->items->firstWhere('fees_class_type_id', $template->id);
            $this->assertNotNull($item);
            $this->assertSame($originalDate, $item->getRawOriginal('due_date_snapshot'));

            $confirmed = $service->confirm($student, $actor, $draft->uuid);
            $this->assertSame(StudentFeeAssignment::CONFIRMED, $confirmed->status);
            $confirmedItemId = (int) $confirmed->items->firstWhere('id', $item->id)->id;

            $laterDate = $originalDate === null ? '2026-12-31' : '2027-01-15';
            DB::table('fees')->where('id', $fee->id)->update(['due_date' => $laterDate]);

            $snapshot = StudentFeeAssignmentItem::query()->findOrFail($confirmedItemId);
            $this->assertSame($originalDate, $snapshot->getRawOriginal('due_date_snapshot'));
            $this->assertSame($laterDate, DB::table('fees')->where('id', $fee->id)->value('due_date'));
        }
    }
}
