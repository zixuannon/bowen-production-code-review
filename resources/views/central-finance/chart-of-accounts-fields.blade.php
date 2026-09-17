<div class="form-row">
    <div class="form-group col-md-4"><label for="coa-group-{{ $formKey }}">{{ __('Finance Group') }}</label>
        <select id="coa-group-{{ $formKey }}" name="group_id" class="form-control" required>
            @foreach($configurableGroups as $group)
                @if(!$coa || (int)$coa->group_id === (int)$group->id)
                    <option value="{{ $group->id }}" @selected($coa?->group_id == $group->id)>{{ $group->name }}</option>
                @endif
            @endforeach
        </select></div>
    <div class="form-group col-md-4"><label for="coa-type-{{ $formKey }}">{{ __('Account Type') }}</label><select id="coa-type-{{ $formKey }}" name="type" class="form-control" required @disabled($coa)>@foreach(\App\Models\CentralFinanceCategory::TYPES as $type)<option value="{{ $type }}" @selected($coa?->type === $type)>{{ __(ucfirst($type)) }}</option>@endforeach</select>@if($coa)<input type="hidden" name="type" value="{{ $coa->type }}">@endif</div>
    <div class="form-group col-md-4"><label for="coa-code-{{ $formKey }}">{{ __('Account Code') }}</label><input id="coa-code-{{ $formKey }}" type="text" name="category_code" class="form-control" value="{{ $coa?->category_code }}" maxlength="80" required placeholder="0101" @readonly($coa)>@if($coa)<small class="text-muted">{{ __('Account Code and Type are locked to preserve historical classification.') }}</small>@endif</div>
    <div class="form-group col-md-8"><label for="coa-name-{{ $formKey }}">{{ __('Account Name') }}</label><input id="coa-name-{{ $formKey }}" name="name" class="form-control" value="{{ $coa?->name }}" maxlength="120" required></div>
    <div class="form-group col-md-4"><label for="coa-status-{{ $formKey }}">{{ __('Status') }}</label><select id="coa-status-{{ $formKey }}" name="is_active" class="form-control"><option value="1" @selected(!$coa || $coa->is_active)>{{ __('Active') }}</option><option value="0" @selected($coa && !$coa->is_active)>{{ __('Inactive') }}</option></select></div>
</div>
<fieldset class="form-group"><legend class="h6">{{ __('Allocated Schools') }}</legend><p class="small text-muted">{{ __('Only active Schools in the selected Finance Group can be allocated. Allocation grants usage, not ownership or a balance.') }}</p><div class="d-flex flex-wrap" style="gap: 1rem">
    @foreach($schools as $allocationSchool)<label class="d-inline-flex align-items-center" style="gap: .5rem"><input type="checkbox" name="school_ids[]" value="{{ $allocationSchool->id }}" style="appearance:auto;opacity:1;position:static;width:1.1rem;height:1.1rem" @checked($coa && $coa->schoolAllocations->where('is_active',true)->contains('school_id',$allocationSchool->id))> {{ $allocationSchool->name }} · {{ $allocationSchool->code }}</label>@endforeach
</div></fieldset>
<div class="form-group"><label for="coa-reason-{{ $formKey }}">{{ __('Audit reason') }}</label><textarea id="coa-reason-{{ $formKey }}" name="reason" class="form-control" required maxlength="1000" rows="2"></textarea></div>
