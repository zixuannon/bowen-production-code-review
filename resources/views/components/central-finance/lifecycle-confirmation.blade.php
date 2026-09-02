<div class="modal fade" id="central-finance-lifecycle-confirmation" tabindex="-1" role="dialog" aria-labelledby="central-finance-lifecycle-confirmation-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="central-finance-lifecycle-confirmation-title">{{ __('Confirm audited lifecycle action') }}</h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button></div>
        <div class="modal-body"><div class="cf-history-notice mb-3">{{ __('This action preserves the original document and its audit history. It cannot be used as a delete action.') }}</div><dl class="row mb-0"><dt class="col-5">{{ __('Object') }}</dt><dd class="col-7" data-lifecycle-confirm="object"></dd><dt class="col-5">{{ __('Amount') }}</dt><dd class="col-7" data-lifecycle-confirm="amount"></dd><dt class="col-5">{{ __('Source / destination') }}</dt><dd class="col-7 text-break" data-lifecycle-confirm="source-destination"></dd><dt class="col-5">{{ __('Current status') }}</dt><dd class="col-7" data-lifecycle-confirm="current-status"></dd><dt class="col-5">{{ __('Result') }}</dt><dd class="col-7" data-lifecycle-confirm="result"></dd><dt class="col-5">{{ __('Reason') }}</dt><dd class="col-7 text-break" data-lifecycle-confirm="reason"></dd></dl><p class="small text-muted mt-3 mb-0">{{ __('A reason is required and will be retained in the audit trail.') }}</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">{{ __('Cancel') }}</button><button type="button" class="btn btn-warning" data-lifecycle-confirm="submit">{{ __('Confirm action') }}</button></div>
    </div></div>
</div>

@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('central-finance-lifecycle-confirmation');
    if (!modal) return;
    var pendingForm = null;
    var lifecycleForms = 'form[data-lifecycle-confirm], form[action*="/refund"], form[action*="/void"], form[action*="/status"], form[action*="/opening-adjustments"], form[action*="/adjustments"]';
    document.querySelectorAll(lifecycleForms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.lifecycleConfirmed === 'true') return;
            event.preventDefault();
            var reason = form.querySelector('[name="reason"]');
            if (reason && !String(reason.value || '').trim()) { reason.focus(); return; }
            pendingForm = form;
            var container = form.closest('tr, article, .card');
            var action = form.getAttribute('action') || '';
            var defaults = {
                object: container && (container.querySelector('.cf-primary-line, h5, .card-title') || {}).textContent,
                amount: container && (container.textContent.match(/[0-9][0-9,]*(?:\\.[0-9]+)?\\s+(?:MMK|USD|CNY)/) || [])[0],
                'source-destination': '',
                'current-status': container && (container.querySelector('.badge') || {}).textContent,
                result: action.indexOf('/refund') !== -1 ? @json(__('Refund with append-only Ledger reversal')) : (action.indexOf('/void') !== -1 ? @json(__('Voided with append-only reversal')) : (action.indexOf('/status') !== -1 ? @json(__('Account status changes; statements remain readable')) : @json(__('New append-only audited record')))),
                reason: reason ? reason.value : ''
            };
            ['object', 'amount', 'source-destination', 'current-status', 'result', 'reason'].forEach(function (name) {
                var output = modal.querySelector('[data-lifecycle-confirm="' + name + '"]');
                if (output) output.textContent = form.dataset['lifecycle' + name.replace(/-([a-z])/g, function (_, letter) { return letter.toUpperCase(); })] || String(defaults[name] || '').trim() || '—';
            });
            if (window.jQuery && window.jQuery.fn.modal) window.jQuery(modal).modal('show');
        });
    });
    var confirm = modal.querySelector('[data-lifecycle-confirm="submit"]');
    if (confirm) confirm.addEventListener('click', function () { if (!pendingForm) return; pendingForm.dataset.lifecycleConfirmed = 'true'; pendingForm.submit(); });
});
</script>
@endonce
