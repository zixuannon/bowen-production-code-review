@extends('layouts.master')

@section('title', __('Student Import V2'))

@section('content')
<div class="content-wrapper">
    <div class="page-header"><h3 class="page-title">{{ __('Student Import V2') }}</h3></div>
    <div class="card"><div class="card-body">
        <div class="row align-items-start">
            <div class="col-lg-4 mb-3 mb-lg-0">
                <section class="ui-form-section h-100 mb-0">
                    <div class="ui-form-section__header">
                        <h5>{{ __('File requirements') }}</h5>
                        <p>{{ __('Use the current XLSX template for this School. Student Code is generated automatically when the import is confirmed.') }}</p>
                    </div>
                    <a class="btn btn-outline-primary btn-block" href="{{ route('students.import-v2.template') }}">{{ __('Download V2 template') }}</a>
                    <ul class="small text-muted pl-3 mt-3 mb-0">
                        <li>{{ __('Accepted file: XLSX') }}</li>
                        <li>{{ __('Preview creates no Student or Finance record.') }}</li>
                        <li>{{ __('Class Section and Academic Year are validated against the current School.') }}</li>
                    </ul>
                </section>
            </div>
            <div class="col-lg-8">
                <section class="ui-form-section mb-0">
                    <div class="ui-form-section__header"><h5>{{ __('Upload and preview') }}</h5><p>{{ __('Confirm is enabled only when the preview has New rows and no Error or Conflict rows.') }}</p></div>
                    <form id="student-import-v2" enctype="multipart/form-data">
                        @csrf
                        <div class="ui-upload-zone mb-3">
                            <label class="ui-upload-zone__title" for="student-import-v2-file">{{ __('Student Import V2 XLSX') }}</label>
                            <span class="ui-upload-zone__help">{{ __('Choose one completed template. The file is validated before any write.') }}</span>
                            <input id="student-import-v2-file" required type="file" name="file" accept=".xlsx" class="form-control-file mt-3" data-ui-file-input>
                            <span class="ui-upload-zone__file" data-ui-file-name data-empty-label="{{ __('No file selected') }}">{{ __('No file selected') }}</span>
                        </div>
                        <button class="btn btn-theme" type="submit">{{ __('Preview and validate') }}</button>
                    </form>
                </section>
            </div>
        </div>
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
    const labels = {
        valid: '{{ __('Valid') }}', new: '{{ __('New') }}', duplicate: '{{ __('Duplicate') }}',
        error: '{{ __('Error') }}', conflict: '{{ __('Conflict') }}', loading: '{{ __('Loading') }}…',
        failed: '{{ __('Preview failed') }}', confirm: '{{ __('Confirm Import') }}',
        confirming: '{{ __('Confirming import') }}…', complete: '{{ __('Import completed') }}'
    };
    const clear = () => { while (result.firstChild) result.removeChild(result.firstChild); };
    const appendText = (tag, value, className = '') => { const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; result.appendChild(node); return node; };
    const addCell = (row, value, label, className = '') => { const cell = document.createElement('td'); cell.textContent = value || '—'; cell.dataset.label = label; if (className) cell.className = className; row.appendChild(cell); };
    const addSummary = (summary) => {
        const wrapper = document.createElement('div'); wrapper.className = 'ui-import-summary'; wrapper.setAttribute('aria-label', '{{ __('Validation summary') }}');
        [['new', labels.valid], ['duplicate', labels.duplicate], ['error', labels.error], ['conflict', labels.conflict]].forEach(([key, label]) => {
            const item = document.createElement('div'); item.className = 'ui-import-summary__item';
            const caption = document.createElement('span'); caption.textContent = label;
            const value = document.createElement('strong'); value.textContent = summary[key] || 0;
            item.append(caption, value); wrapper.appendChild(item);
        });
        result.appendChild(wrapper);
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); clear(); appendText('p', labels.loading, 'text-muted');
        let response;
        try {
            response = await fetch('{{ route('students.import-v2.preview') }}', {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: new FormData(form)});
        } catch (error) {
            clear(); appendText('div', labels.failed, 'ui-error-state'); return;
        }
        const payload = await response.json().catch(() => ({})); clear();
        if (!response.ok) { appendText('div', payload.message || labels.failed, 'alert alert-danger'); return; }
        addSummary(payload.summary || {});
        const hint = appendText('p', '{{ __('Swipe horizontally to review every validation column.') }}', 'ui-table-scroll-hint'); hint.setAttribute('aria-hidden', 'true');
        const table = document.createElement('table'); table.className = 'table ui-responsive-list ui-mobile-cards';
        const headers = ['#', '{{ __('Import Reference') }}', '{{ __('Student Code') }}', '{{ __('Student') }}', '{{ __('Class Section') }}', '{{ __('Session Year') }}', '{{ __('Status') }}', '{{ __('Reasons') }}'];
        const head = document.createElement('thead'); const headerRow = document.createElement('tr'); headers.forEach(label => { const cell = document.createElement('th'); cell.textContent = label; headerRow.appendChild(cell); }); head.appendChild(headerRow); table.appendChild(head);
        const body = document.createElement('tbody'); (payload.rows || []).forEach((item) => { const row = document.createElement('tr'); addCell(row, item.line, '#'); addCell(row, item.import_reference, headers[1], 'ui-cell-primary'); addCell(row, item.student_code || '{{ __('Assigned on confirm') }}', headers[2]); addCell(row, item.student_name, headers[3]); addCell(row, item.class_section, headers[4]); addCell(row, item.academic_year, headers[5]); addCell(row, item.status, headers[6]); addCell(row, [...(item.errors || []), ...(item.warnings || [])].join(' '), headers[7]); body.appendChild(row); }); table.appendChild(body);
        const wrapper = document.createElement('div'); wrapper.className = 'table-responsive ui-responsive-list-wrap'; wrapper.appendChild(table); result.appendChild(wrapper);
        const unsafe = Number(payload.summary?.error || 0) + Number(payload.summary?.conflict || 0); const confirm = document.createElement('button'); confirm.className = 'btn btn-theme mt-3'; confirm.type = 'button'; confirm.textContent = labels.confirm; confirm.disabled = unsafe > 0 || Number(payload.summary?.new || 0) === 0; result.appendChild(confirm);
        confirm.addEventListener('click', async () => {
            confirm.disabled = true; confirm.textContent = labels.confirming;
            const confirmed = await fetch('{{ route('students.import-v2.confirm') }}', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify({preview_token: payload.preview_token})});
            const final = await confirmed.json().catch(() => ({}));
            appendText('div', confirmed.ok ? (final.message || labels.complete) : (final.message || labels.failed), confirmed.ok ? 'alert alert-success mt-3' : 'alert alert-danger mt-3');
        });
    });
})();
</script>
@endsection
