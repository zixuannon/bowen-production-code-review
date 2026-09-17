<?php

namespace App\Services;

use App\Models\CentralFinanceCategory;
use App\Models\CentralFinanceCategorySchoolAllocation;
use App\Models\CentralFinanceUser;
use App\Models\FinanceGroup;
use App\Models\FinanceGroupSchool;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Definitions and allocations only. Never rewrites a financial document or Ledger entry. */
final class CentralChartOfAccountsService
{
    public function __construct(private readonly CentralFinanceConfigurationAuthorizationService $authorization) {}

    public function save(CentralFinanceUser $actor, array $input, ?int $categoryId = null): CentralFinanceCategory
    {
        CentralChartOfAccountsSchema::assertComplete();
        $data = Validator::make($input, [
            'group_id' => ['required','integer','min:1'],
            'type' => ['required', Rule::in(CentralFinanceCategory::TYPES)],
            'category_code' => ['required','string','max:80','regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'name' => ['required','string','max:120'], 'is_active' => ['required','boolean'],
            'school_ids' => ['present','array'], 'school_ids.*' => ['integer','min:1','distinct'],
            'reason' => ['required','string','max:1000'],
        ])->validate();
        $data['category_code'] = strtoupper(trim($data['category_code']));
        $data['name'] = trim($data['name']); $data['reason'] = trim($data['reason']);
        if ($data['name'] === '' || $data['reason'] === '' || str_starts_with($data['category_code'], 'CATEGORY-')) throw ValidationException::withMessages(['category_code' => 'Enter an explicit Account Code, name and audit reason.']);
        $groupId = (int) $data['group_id'];
        $this->authorization->assertHeadFinanceCanConfigureGroup($actor, $groupId);
        return DB::connection('mysql')->transaction(function () use ($actor, $data, $groupId, $categoryId): CentralFinanceCategory {
            // Serialize definitions and allocations within the group; DB unique remains the final race guard.
            FinanceGroup::on('mysql')->whereKey($groupId)->lockForUpdate()->firstOrFail();
            $category = $categoryId ? CentralFinanceCategory::on('mysql')->lockForUpdate()->findOrFail($categoryId) : new CentralFinanceCategory;
            if ($category->exists && (int) $category->group_id !== $groupId) throw ValidationException::withMessages(['group_id' => 'Legacy or other-group accounts require an explicitly reviewed mapping, not an edit.']);
            if ($category->exists && ($category->category_code !== $data['category_code'] || $category->type !== $data['type'])) throw ValidationException::withMessages(['category_code'=>'Account Code and Type are immutable; create a new definition instead of rewriting historical classification.']);
            $schoolIds = array_map('intval', $data['school_ids']);
            // A form may omit Schools the actor can no longer see. An omitted
            // allocation is still a mutation, not an implicit revocation grant.
            $existingSchoolIds = $category->exists ? $category->schoolAllocations()->lockForUpdate()->pluck('school_id')->map(fn ($id)=>(int)$id)->all() : [];
            foreach (array_unique(array_merge($existingSchoolIds, $schoolIds)) as $affectedSchoolId) {
                $this->authorization->assertHeadFinanceCanConfigureSchool($actor, School::on('mysql')->findOrFail($affectedSchoolId));
            }
            foreach ($schoolIds as $schoolId) {
                $school = School::on('mysql')->findOrFail($schoolId);
                $this->authorization->assertHeadFinanceCanConfigureSchool($actor, $school);
                if (!FinanceGroupSchool::on('mysql')->where(['group_id'=>$groupId,'school_id'=>$schoolId,'status'=>'active'])
                    ->where(fn ($q) => $q->whereNull('active_from')->orWhereDate('active_from','<=',now()->toDateString()))
                    ->where(fn ($q) => $q->whereNull('active_to')->orWhereDate('active_to','>=',now()->toDateString()))->exists()) {
                    throw ValidationException::withMessages(['school_ids'=>'Every allocation must be an active member of this Finance Group.']);
                }
            }
            if (CentralFinanceCategory::on('mysql')->where('group_id',$groupId)->where('category_code',$data['category_code'])->when($category->exists,fn ($q) => $q->whereKeyNot($category->id))->exists()) throw ValidationException::withMessages(['category_code'=>'This Account Code already exists in the Finance Group.']);
            $before = $category->exists ? $this->snapshot($category) : null;
            $category->fill(['group_id'=>$groupId,'school_id'=>null,'type'=>$data['type'],'category_code'=>$data['category_code'],'name'=>$data['name'],'is_active'=>(bool)$data['is_active']])->save();
            CentralFinanceCategorySchoolAllocation::on('mysql')->where('category_id',$category->id)->whereNotIn('school_id',$schoolIds)->update(['is_active'=>false,'updated_at'=>now()]);
            foreach ($schoolIds as $schoolId) CentralFinanceCategorySchoolAllocation::on('mysql')->updateOrCreate(['category_id'=>$category->id,'school_id'=>$schoolId],['is_active'=>true]);
            DB::connection('mysql')->table('central_finance_category_audits')->insert(['category_id'=>$category->id,'group_id'=>$groupId,'actor_id'=>$actor->id,'action'=>$before ? 'updated' : 'created','reason'=>$data['reason'],'before'=>$before ? json_encode($before,JSON_THROW_ON_ERROR) : null,'after'=>json_encode($this->snapshot($category),JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return $category->fresh();
        });
    }

    private function snapshot(CentralFinanceCategory $category): array
    {
        return array_merge($category->only(['id','group_id','school_id','type','category_code','name','is_active']), ['school_ids'=>$category->schoolAllocations()->where('is_active',true)->orderBy('school_id')->pluck('school_id')->all()]);
    }
}
