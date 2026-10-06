<?php

namespace Tests\Feature;

use App\Models\Fee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalFeeDueDateEditTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $tenantDbAsDefault = true;
    protected $connectionsToTransact = ['mysql', 'school'];

    public function test_the_same_fee_persists_null_to_date_and_back_without_stale_accessor_state(): void
    {
        $section = DB::table('class_sections')->where('school_id', 1)->first();
        $this->assertNotNull($section);

        $fee = new Fee();
        $fee->forceFill([
            'name' => 'Due Date Edit '.Str::random(6), 'due_date' => null,
            'due_charges' => 0, 'due_charges_amount' => 0,
            'class_id' => $section->class_id, 'school_id' => 1, 'session_year_id' => 1,
        ]);
        $fee->save();

        $this->assertNull(DB::table('fees')->where('id', $fee->id)->value('due_date'));
        $fee->due_date = '2026-12-31';
        $fee->save();
        $this->assertSame('2026-12-31', DB::table('fees')->where('id', $fee->id)->value('due_date'));
        $this->assertSame('31-12-2026', $fee->fresh()->due_date);

        $fee->due_date = null;
        $fee->save();
        $this->assertNull(DB::table('fees')->where('id', $fee->id)->value('due_date'));
        $this->assertNull($fee->fresh()->due_date);
        $this->assertSame(1, DB::table('fees')->where('id', $fee->id)->count());
    }
}
