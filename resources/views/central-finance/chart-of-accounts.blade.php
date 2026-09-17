<div class="card mb-3"><div class="card-body">
    <h5>{{ __('Chart of Accounts') }}</h5>
    <p class="text-muted">{{ __('Central definitions are shared through explicit School allocations. Cash-flow only; no debit/credit journal.') }}</p>
    @if($canConfigureAccounts)
        <form method="POST" action="{{ route('central-finance.categories.store') }}">
            @csrf
            @include('central-finance.chart-of-accounts-fields', ['coa' => null, 'formKey' => 'new'])
            <button class="btn btn-theme">{{ __('Create Account') }}</button>
        </form>
    @endif
</div></div>
<div class="card"><div class="card-body"><div class="table-responsive">
    <table class="table"><thead><tr><th>{{ __('Account Type') }}</th><th>{{ __('Account Code') }}</th><th>{{ __('Account Name') }}</th><th>{{ __('Allocated Schools') }}</th><th>{{ __('Status') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>@forelse($categories as $category)
        <tr><td>{{ __(ucfirst($category->type)) }}</td><td>{{ $category->group_id ? $category->category_code : __('Legacy mapping required') }}</td><td>{{ $category->name }}</td>
            <td>@if($category->group_id){{ $schools->whereIn('id', $category->schoolAllocations->where('is_active',true)->pluck('school_id'))->pluck('name')->join(', ') ?: '—' }}@else{{ $schools->firstWhere('id',$category->school_id)?->name }}@endif</td>
            <td>{{ $category->is_active ? __('Active') : __('Inactive') }}</td>
            <td>@if($canConfigureAccounts && $category->group_id && $configurableGroups->contains('id',$category->group_id))
                <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#coa-edit-{{ $category->id }}" aria-expanded="false" aria-controls="coa-edit-{{ $category->id }}">{{ __('Manage') }} · {{ $category->category_code }}</button>
                @else<span class="text-muted">{{ $category->group_id ? __('Read only') : __('Legacy mapping required') }}</span>@endif</td></tr>
        @if($canConfigureAccounts && $category->group_id && $configurableGroups->contains('id',$category->group_id))
            <tr class="collapse" id="coa-edit-{{ $category->id }}"><td colspan="6"><form method="POST" action="{{ route('central-finance.categories.update',$category->id) }}">@csrf
                @include('central-finance.chart-of-accounts-fields',['coa'=>$category,'formKey'=>'edit-'.$category->id])
                <button class="btn btn-theme">{{ __('Save with audit') }}</button>
            </form></td></tr>
        @endif
    @empty<tr><td colspan="6" class="text-muted">{{ __('No Chart of Accounts definitions yet.') }}</td></tr>@endforelse</tbody></table>
</div></div></div>
