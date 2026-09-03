@extends('layouts.master')

@section('title', __('Student Import V2'))

@section('content')
    <div class="content-wrapper">
        <div class="page-header"><h3 class="page-title">{{ __('Student Import V2') }}</h3></div>
        <div class="card"><div class="card-body">
            <p class="text-muted mb-4">{{ __('Preview has no write. Confirm creates only new Students and their compulsory fee assignments.') }}</p>
            <a class="btn btn-outline-primary mb-3" href="{{ route('students.import-v2.template') }}">{{ __('Download V2 template') }}</a>
            <form id="student-import-v2" enctype="multipart/form-data">
                @csrf
                <div class="row">
                    <div class="form-group col-md-4"><label>{{ __('Session Year') }}</label><select required name="session_year_id" class="form-control">@foreach($sessionYears as $year)<option value="{{ $year->id }}" @selected($year->default)>{{ $year->name }}</option>@endforeach</select></div>
                    <div class="form-group col-md-4"><label>{{ __('Class Section') }}</label><select required name="class_section_id" class="form-control"><option value="">{{ __('Select') }}</option>@foreach($class_section as $section)<option value="{{ $section->id }}">{{ $section->full_name }}</option>@endforeach</select></div>
                    <div class="form-group col-md-4"><label>{{ __('File') }}</label><input required type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control"></div>
                </div>
                <button class="btn btn-theme" type="submit">{{ __('Preview') }}</button>
            </form>
            <div id="student-import-v2-result" class="mt-4" aria-live="polite"></div>
        </div></div>
    </div>
@endsection

@section('js')
<script>
(() => {
    const form = document.getElementById('student-import-v2');
    const result = document.getElementById('student-import-v2-result');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const clear = () => { while (result.firstChild) result.removeChild(result.firstChild); };
    const appendText = (tag, value) => { const node = document.createElement(tag); node.textContent = value; result.appendChild(node); return node; };
    const addCell = (row, value) => { const cell = document.createElement('td'); cell.textContent = value || ''; row.appendChild(cell); };
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); clear(); appendText('p', '{{ __('Loading') }}…');
        const response = await fetch('{{ route('students.import-v2.preview') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: new FormData(form)});
        const payload = await response.json(); clear();
        if (!response.ok) { appendText('p', payload.message || '{{ __('Preview failed') }}'); return; }
        appendText('p', Object.entries(payload.summary).map(([key, value]) => `${key}: ${value}`).join(' · '));
        const table = document.createElement('table'); table.className = 'table';
        const head = document.createElement('thead'); const headerRow = document.createElement('tr'); ['#', '{{ __('Student Code') }}', '{{ __('Status') }}', '{{ __('Errors') }}', '{{ __('Warnings') }}'].forEach(label => { const cell = document.createElement('th'); cell.textContent = label; headerRow.appendChild(cell); }); head.appendChild(headerRow); table.appendChild(head);
        const body = document.createElement('tbody'); payload.rows.forEach((item) => { const row = document.createElement('tr'); addCell(row, item.line); addCell(row, item.student_code); addCell(row, item.status); addCell(row, (item.errors || []).join(' ')); addCell(row, (item.warnings || []).join(' ')); body.appendChild(row); }); table.appendChild(body);
        const wrapper = document.createElement('div'); wrapper.className = 'table-responsive'; wrapper.appendChild(table); result.appendChild(wrapper);
        const confirm = document.createElement('button'); confirm.className = 'btn btn-theme'; confirm.type = 'button'; confirm.textContent = '{{ __('Confirm') }}'; result.appendChild(confirm);
        confirm.addEventListener('click', async () => {
            const confirmed = await fetch('{{ route('students.import-v2.confirm') }}', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify({preview_token: payload.preview_token})});
            const final = await confirmed.json(); appendText('pre', JSON.stringify(final, null, 2));
        });
    });
})();
</script>
@endsection
