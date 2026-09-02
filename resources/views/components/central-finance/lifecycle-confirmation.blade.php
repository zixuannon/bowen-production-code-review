<div class="modal fade" id="central-finance-lifecycle-confirmation" tabindex="-1" role="dialog" aria-labelledby="central-finance-lifecycle-confirmation-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="central-finance-lifecycle-confirmation-title">{{ __('Confirm audited lifecycle action') }}</h5><button type="button" class="close" data-dismiss="modal" aria-label="{{ __('Close') }}"><span aria-hidden="true">&times;</span></button></div>
        <div class="modal-body"><div class="cf-history-notice mb-3">{{ __('This action preserves the original document and its audit history. It cannot be used as a delete action.') }}</div><dl class="row mb-0"><dt class="col-5">{{ __('Object') }}</dt><dd class="col-7" data-lifecycle-confirm="object"></dd><dt class="col-5">{{ __('Amount') }}</dt><dd class="col-7" data-lifecycle-confirm="amount"></dd><dt class="col-5">{{ __('Source / destination') }}</dt><dd class="col-7 text-break" data-lifecycle-confirm="source-destination"></dd><dt class="col-5">{{ __('Current status') }}</dt><dd class="col-7" data-lifecycle-confirm="current-status"></dd><dt class="col-5">{{ __('Result') }}</dt><dd class="col-7" data-lifecycle-confirm="result"></dd><dt class="col-5">{{ __('Reason') }}</dt><dd class="col-7 text-break" data-lifecycle-confirm="reason"></dd></dl><div class="form-group mt-3 d-none" data-lifecycle-confirm="reason-field"><label for="central-finance-lifecycle-reason">{{ __('Reason') }}</label><textarea id="central-finance-lifecycle-reason" class="form-control" rows="3" maxlength="2000" data-lifecycle-confirm="reason-input"></textarea></div><p class="small text-muted mt-3 mb-0">{{ __('A reason is required and will be retained in the audit trail.') }}</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">{{ __('Cancel') }}</button><button type="button" class="btn btn-warning" data-lifecycle-confirm="submit">{{ __('Confirm action') }}</button></div>
    </div></div>
</div>

@once
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('central-finance-lifecycle-confirmation');
    if (!modal) return;
    var pendingForm = null;
    var pendingModalReason = false;
    var lifecycleForms = 'form[data-lifecycle-confirm], form[action*="/refund"], form[action*="/void"], form[action*="/status"], form[action*="/opening-adjustments"], form[action*="/adjustments"]';
    document.querySelectorAll(lifecycleForms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.lifecycleConfirmed === 'true') return;
            event.preventDefault();
            var reason = form.querySelector('[name="reason"]');
            var modalReason = form.dataset.lifecycleModalReason === 'true';
            if (reason && !String(reason.value || '').trim()) { reason.focus(); return; }
            pendingForm = form;
            pendingModalReason = modalReason;
            var reasonField = modal.querySelector('[data-lifecycle-confirm="reason-field"]');
            var reasonInput = modal.querySelector('[data-lifecycle-confirm="reason-input"]');
            if (reasonField) reasonField.classList.toggle('d-none', !modalReason);
            if (reasonInput) {
                reasonInput.required = modalReason;
                reasonInput.value = modalReason ? '' : (reason ? reason.value : '');
            }
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
                var datasetKey = 'lifecycle' + name.replace(/(^|-)([a-z])/g, function (_, __, letter) { return letter.toUpperCase(); });
                if (output) output.textContent = form.dataset[datasetKey] || String(defaults[name] || '').trim() || '—';
            });
            var confirmButton = modal.querySelector('[data-lifecycle-confirm="submit"]');
            if (confirmButton) confirmButton.textContent = form.dataset.lifecycleConfirmLabel || @json(__('Confirm action'));
            if (window.jQuery && window.jQuery.fn.modal) window.jQuery(modal).modal('show');
        });
        form.querySelectorAll('[data-lifecycle-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            });
        });
    });
    var confirm = modal.querySelector('[data-lifecycle-confirm="submit"]');
    if (confirm) confirm.addEventListener('click', function () {
        if (!pendingForm) return;
        if (pendingModalReason) {
            var reasonInput = modal.querySelector('[data-lifecycle-confirm="reason-input"]');
            var value = String(reasonInput && reasonInput.value || '').trim();
            if (!value) { if (reasonInput) reasonInput.focus(); return; }
            var hiddenReason = pendingForm.querySelector('input[type="hidden"][name="reason"]');
            if (!hiddenReason) {
                hiddenReason = document.createElement('input');
                hiddenReason.type = 'hidden';
                hiddenReason.name = 'reason';
                pendingForm.appendChild(hiddenReason);
            }
            hiddenReason.value = value;
        }
        pendingForm.dataset.lifecycleConfirmed = 'true';
        pendingForm.submit();
    });
});
</script>
@endonce
