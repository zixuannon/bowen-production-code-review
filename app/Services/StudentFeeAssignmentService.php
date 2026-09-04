<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\FeesClassType;
use App\Models\StudentFeeAssignment;
use App\Models\StudentFeeAssignmentItem;
use App\Models\Students;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-only student fee setup. Confirmed snapshots are the source for the
 * Central receivable projection; this service never writes tenant payments.
 */
final class StudentFeeAssignmentService
{
    public function __construct(private readonly CentralFinanceReceivablePublisher $publisher) {}

    /** @return Collection<int, FeesClassType> */
    public function availableItems(Students $student): Collection
    {
        $this->assertStudentShape($student);
        $classId = $this->studentClassId($student);
        $assigned = StudentFeeAssignmentItem::query()
            ->where('source_type', StudentFeeAssignmentItem::FEES_CLASS_TYPE)
            ->where('status', StudentFeeAssignmentItem::ACTIVE)
            ->whereHas('assignment', fn ($query) => $query->where('student_id', $student->id)->where('academic_year_id', $student->session_year_id)->where('status', StudentFeeAssignment::CONFIRMED))
            ->pluck('source_id')->map(fn ($id) => (string) $id)->all();

        $query = FeesClassType::query()->with(['fee', 'fees_type'])
            ->where('class_id', $classId)
            ->where('school_id', $student->school_id)
            ->whereNotIn('id', $assigned);
        if (\Illuminate\Support\Facades\Schema::hasColumn('fees_class_types', 'deleted_at')) $query->whereNull('deleted_at');
        return $query->get()
            ->filter(fn (FeesClassType $item) => $item->fee !== null && $item->fee->getRawOriginal('deleted_at') === null && (int) $item->fee->session_year_id === (int) $student->session_year_id)
            ->values();
    }

    public function latestDraft(Students $student): ?StudentFeeAssignment
    {
        return StudentFeeAssignment::query()->with('items')->where([
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'academic_year_id' => $student->session_year_id,
            'class_id' => $this->studentClassId($student),
            'status' => StudentFeeAssignment::DRAFT,
        ])->latest('id')->first();
    }

    /** @return Collection<int, FeesClassType> */
    public function availableAdditionalItems(Students $student): Collection
    {
        return $this->availableItems($student)->where('optional', true)->values();
    }

    /**
     * Canonical optional templates for the Student's present School/year/class.
     * Unlike availableAdditionalItems(), this retains already-assigned rows so
     * an idempotent Central request can re-project a previously confirmed item
     * without treating its client supplied id as a new price or fee source.
     *
     * @return Collection<int, FeesClassType>
     */
    public function configuredAdditionalItems(Students $student): Collection
    {
        $this->assertStudentShape($student);
        $query = FeesClassType::query()->with(['fee', 'fees_type'])
            ->where('class_id', $this->studentClassId($student))
            ->where('school_id', $student->school_id)
            ->where('optional', true);
        if (\Illuminate\Support\Facades\Schema::hasColumn('fees_class_types', 'deleted_at')) $query->whereNull('deleted_at');

        return $query->get()
            ->filter(fn (FeesClassType $item) => $item->fee !== null && $item->fee->getRawOriginal('deleted_at') === null && (int) $item->fee->session_year_id === (int) $student->session_year_id)
            ->values();
    }

    /** @param list<mixed> $requestedOptionalIds */
    public function saveDraft(Students $student, User $actor, array $requestedOptionalIds): StudentFeeAssignment
    {
        $this->assertActor($student, $actor);
        $available = $this->availableItems($student);
        $optionalIds = collect($requestedOptionalIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        $allowedOptional = $available->where('optional', 1)->keyBy('id');
        if ($optionalIds->diff($allowedOptional->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['optional_fee_ids' => 'Selected optional fees are not valid for this Student class.']);
        }

        $selected = $available->filter(fn (FeesClassType $item) => !(bool) $item->optional || $optionalIds->contains((int) $item->id));
        return DB::transaction(function () use ($student, $selected): StudentFeeAssignment {
            $assignment = $this->latestDraft($student) ?? StudentFeeAssignment::create([
                'uuid' => (string) Str::uuid(), 'school_id' => $student->school_id, 'student_id' => $student->id,
                'academic_year_id' => $student->session_year_id, 'class_id' => $this->studentClassId($student), 'assignment_type' => StudentFeeAssignment::INITIAL, 'status' => StudentFeeAssignment::DRAFT,
            ]);
            // Drafts are the only mutable records. Confirmed snapshots are never rebuilt.
            $assignment->items()->delete();
            foreach ($selected as $template) {
                $assignment->items()->create($this->snapshot($template));
            }
            return $assignment->fresh('items');
        });
    }

    /** @param list<mixed> $requestedOptionalIds */
    public function saveAdditionalDraft(Students $student, User $actor, array $requestedOptionalIds): StudentFeeAssignment
    {
        $this->assertActor($student, $actor);
        $optional = $this->availableAdditionalItems($student)->keyBy('id');
        $selected = collect($requestedOptionalIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        if ($selected->isEmpty() || $selected->diff($optional->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['optional_fee_ids' => 'Select one eligible optional fee for this Student.']);
        }
        return DB::transaction(function () use ($student, $selected, $optional): StudentFeeAssignment {
            $assignment = StudentFeeAssignment::create([
                'uuid' => (string) Str::uuid(), 'school_id' => $student->school_id, 'student_id' => $student->id,
                'academic_year_id' => $student->session_year_id, 'class_id' => $this->studentClassId($student),
                'assignment_type' => StudentFeeAssignment::ADDITIONAL, 'status' => StudentFeeAssignment::DRAFT,
            ]);
            foreach ($selected as $id) $assignment->items()->create($this->snapshot($optional->get($id)));
            return $assignment->fresh('items');
        });
    }

    public function confirm(Students $student, User $actor, string $assignmentUuid): StudentFeeAssignment
    {
        $this->assertActor($student, $actor);
        try {
            $assignment = DB::transaction(function () use ($student, $actor, $assignmentUuid): StudentFeeAssignment {
            $assignment = StudentFeeAssignment::query()->with('items')->where([
                'uuid' => $assignmentUuid, 'school_id' => $student->school_id, 'student_id' => $student->id,
            ])->lockForUpdate()->firstOrFail();
            if ($assignment->status === StudentFeeAssignment::CONFIRMED) return $assignment;
            if ($assignment->status !== StudentFeeAssignment::DRAFT || $assignment->items->isEmpty()) {
                throw ValidationException::withMessages(['assignment' => 'A non-empty draft assignment is required before confirmation.']);
            }
            foreach ($assignment->items->where('status', StudentFeeAssignmentItem::ACTIVE) as $item) {
                DB::table('student_fee_assignment_source_locks')->insert([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'academic_year_id' => $student->session_year_id,
                    'source_type' => $item->source_type,
                    'source_id' => (string) $item->source_id,
                    'student_fee_assignment_item_id' => $item->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $assignment->update(['status' => StudentFeeAssignment::CONFIRMED, 'confirmed_at' => now(), 'confirmed_by' => $actor->id]);
            return $assignment->fresh('items');
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'student_fee_assignment_source_lock')) {
                throw ValidationException::withMessages(['assignment' => 'This fee is already assigned to this Student for the selected academic year.']);
            }

            throw $exception;
        }
        // Central outages cannot roll back the tenant source-of-truth. The established publisher logs a deferred retry.
        $this->publisher->studentFeeAssignmentConfirmed((int) $student->school_id, (int) $student->id);
        return $assignment;
    }

    private function snapshot(FeesClassType $template): array
    {
        $currency = strtoupper((string) ($template->fee_currency ?: $template->fee?->currency ?: 'MMK'));
        return [
            'uuid' => (string) Str::uuid(), 'fee_id' => $template->fees_id, 'fees_class_type_id' => $template->id,
            'fees_type_id' => $template->fees_type_id, 'description_snapshot' => (string) ($template->fee?->name ?: 'Assigned fee'),
            'due_date_snapshot' => $template->fee?->getRawOriginal('due_date'), 'amount_snapshot' => (float) $template->amount,
            'currency_snapshot' => $currency, 'optional_snapshot' => (bool) $template->optional,
            // Preserve the legacy Central identity: Student + FeesClassType.id.
            'source_type' => StudentFeeAssignmentItem::FEES_CLASS_TYPE, 'source_id' => (string) $template->id,
            'status' => StudentFeeAssignmentItem::ACTIVE,
        ];
    }

    private function assertStudentShape(Students $student): void
    {
        if (!$student->school_id || !$this->studentClassId($student) || !$student->session_year_id) {
            throw ValidationException::withMessages(['student' => 'Student must have a School, class, and academic year before fee setup.']);
        }
    }

    private function studentClassId(Students $student): int
    {
        if ((int) $student->class_id > 0) {
            return (int) $student->class_id;
        }

        return (int) ($student->class_section?->class_id ?? $student->class_section()->value('class_id'));
    }

    private function assertActor(Students $student, User $actor): void
    {
        $this->assertStudentShape($student);
        if ((int) $student->school_id !== (int) $actor->school_id) throw new AuthorizationException('Student fee setup is restricted to the current School.');
    }
}
