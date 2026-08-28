"use strict";

// Student Admission has one Guardian controller. Select2 chooses only an id;
// existing Guardian details always come from the tenant-scoped endpoint.
$(function () {
    const $search = $('#guardian_admission_guardian_id');
    const $form = $('#create-form');
    const $submit = $('#create-btn');

    if (!$search.length || !$form.length || !$search.data('guardianAdmissionController')) return;

    // Keep the controller bound to the original <select>, not the Select2
    // presentation container. The marker is harmless UI metadata and gives
    // browser acceptance a concrete proof that this page controller, rather
    // than a legacy Guardian handler, owns the admission selector.
    $search.attr('data-guardian-admission-controller-state', 'ready');

    const state = { mode: 'empty', guardianId: '', guardian: null, request: null, generation: 0 };
    const field = (value) => value == null ? '' : String(value).trim();
    const isEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    const $canonical = {
        mode: $('#guardian_mode'), id: $('#guardian_id'), email: $('#guardian_email'),
        firstName: $('#guardian_first_name'), lastName: $('#guardian_last_name'),
        mobile: $('#guardian_mobile'), gender: $('#guardian_gender'),
    };
    // Browser automation intentionally redacts contact and hidden-control
    // values. Publish only boolean integrity state on the admission selector so
    // UAT can prove the real page's canonical state without disclosing any
    // Guardian information.
    const updateDiagnosticState = () => {
        const values = {
            guardian_id_present: Boolean(field($canonical.id.val())),
            guardian_email_present: Boolean(field($canonical.email.val())),
            guardian_first_name_present: Boolean(field($canonical.firstName.val())),
            guardian_last_name_present: Boolean(field($canonical.lastName.val())),
            guardian_mobile_present: Boolean(field($canonical.mobile.val())),
            guardian_gender_present: Boolean(field($canonical.gender.val())),
        };
        const formData = new FormData($form[0]);
        const formDataFieldPresence = Object.fromEntries(Object.keys(values).map((key) => {
            const fieldName = key.replace(/_present$/, '');
            return [key, Boolean(field(formData.get(fieldName)))];
        }));
        const existingRequired = [
            'guardian_id_present', 'guardian_email_present', 'guardian_first_name_present',
            'guardian_last_name_present', 'guardian_mobile_present', 'guardian_gender_present',
        ];
        const newRequired = existingRequired.filter((key) => key !== 'guardian_id_present');
        const required = state.mode === 'existing' ? existingRequired : (state.mode === 'new' ? newRequired : []);
        const diagnostic = {
            controller_initialized: true,
            mode: state.mode,
            selected_guardian_id: state.guardianId ? true : false,
            ...values,
            canonical_state_complete: required.length > 0 && required.every((key) => values[key]),
            formdata_field_presence_complete: required.length > 0 && required.every((key) => formDataFieldPresence[key]),
        };
        $search.attr('data-guardian-admission-diagnostic', JSON.stringify(diagnostic));
        return diagnostic;
    };
    // Admission's canonical fields are plain form controls. Write both their
    // live and default values from this one controller so a legacy form reset
    // or a Select2 redraw cannot leave an Existing Guardian visibly selected
    // while its submit payload is empty.
    const writeCanonical = ($input, value, options = {}) => {
        const normalized = field(value);
        $input.each((_index, input) => {
            input.value = normalized;
            input.defaultValue = normalized;
            if (Object.prototype.hasOwnProperty.call(options, 'readonly')) input.readOnly = options.readonly;
        });
        return $input;
    };
    const displayGender = () => $('input[name="guardian_gender_display"]:checked').val() || 'male';
    const selectedId = () => field($search.val());
    const selectedTagEmail = () => {
        const data = $search.select2('data')[0] || {};
        // Existing result text is normally its email. Its stable select value
        // remains numeric, so only an email-shaped id can be a new tag.
        return [data.id, selectedId()].map(field).find(isEmail) || '';
    };
    const validDto = (guardian, id) => Boolean(
        guardian && field(guardian.id) === field(id) && field(guardian.email)
        && field(guardian.first_name) && field(guardian.last_name) && field(guardian.mobile)
        && ['male', 'female'].includes(field(guardian.gender))
    );
    const setBusy = (busy) => {
        $search.attr('aria-busy', busy ? 'true' : 'false');
        $submit.prop('disabled', busy);
    };
    const showError = (message) => {
        let $error = $('#guardian-admission-error');
        if (!$error.length) {
            $error = $('<div id="guardian-admission-error" class="text-danger mt-2" role="alert"></div>');
            $search.closest('.form-group').append($error);
        }
        $error.text(message).removeClass('d-none');
    };
    const clearError = () => $('#guardian-admission-error').addClass('d-none').text('');
    const abortPending = () => {
        state.generation += 1;
        if (state.request) state.request.abort();
        state.request = null;
    };
    const clearImage = () => {
        $('input[name="guardian_image"]').val('');
        $('#guardian-image-preview').attr('src', '');
        $('#guardian_image').siblings('span').find('button').prop('disabled', false);
    };
    const setDisplayGender = (gender, locked) => {
        $('#guardian_male').prop('checked', gender === 'male').prop('disabled', locked);
        $('#guardian_female').prop('checked', gender === 'female').prop('disabled', locked);
    };
    const clearCanonicalFields = () => {
        writeCanonical($canonical.mode, 'empty');
        writeCanonical($canonical.id, '');
        writeCanonical($canonical.email, '');
        writeCanonical($canonical.firstName, '', {readonly: false});
        writeCanonical($canonical.lastName, '', {readonly: false});
        writeCanonical($canonical.mobile, '', {readonly: false});
        writeCanonical($canonical.gender, 'male');
        setDisplayGender('male', false);
        clearImage();
        updateDiagnosticState();
    };
    const applyStateToForm = () => {
        writeCanonical($canonical.mode, state.mode);
        if (state.mode === 'existing') {
            const guardian = state.guardian;
            writeCanonical($canonical.id, guardian.id);
            writeCanonical($canonical.email, guardian.email);
            writeCanonical($canonical.firstName, guardian.first_name, {readonly: true});
            writeCanonical($canonical.lastName, guardian.last_name, {readonly: true});
            writeCanonical($canonical.mobile, guardian.mobile, {readonly: true});
            writeCanonical($canonical.gender, guardian.gender);
            setDisplayGender(field(guardian.gender), true);
            clearImage();
            $('#guardian-image-preview').attr('src', field(guardian.image));
            $('#guardian_image').siblings('span').find('button').prop('disabled', true);
            updateDiagnosticState();
            return;
        }
        if (state.mode === 'new') {
            writeCanonical($canonical.id, '');
            writeCanonical($canonical.email, state.email);
            $canonical.firstName.prop('readonly', false);
            $canonical.lastName.prop('readonly', false);
            $canonical.mobile.prop('readonly', false);
            writeCanonical($canonical.gender, displayGender());
            setDisplayGender(displayGender(), false);
            updateDiagnosticState();
            return;
        }
        clearCanonicalFields();
    };
    const clearState = () => {
        abortPending();
        state.mode = 'empty'; state.guardianId = ''; state.guardian = null; delete state.email;
        clearError(); setBusy(false); clearCanonicalFields();
        $search.attr('data-guardian-admission-controller-state', 'ready');
    };
    const selectNewGuardian = (email) => {
        abortPending();
        state.mode = 'new'; state.guardianId = ''; state.guardian = null; state.email = email;
        clearError(); clearCanonicalFields(); applyStateToForm(); setBusy(false);
        $search.attr('data-guardian-admission-controller-state', 'new-ready');
    };
    const selectExistingGuardian = (id) => {
        abortPending();
        const currentGeneration = state.generation;
        state.mode = 'existing'; state.guardianId = field(id); state.guardian = null; delete state.email;
        clearError(); clearCanonicalFields(); writeCanonical($canonical.mode, 'existing'); writeCanonical($canonical.id, state.guardianId); $search.attr('data-guardian-admission-controller-state', 'loading'); setBusy(true);
        const request = $.ajax({
            url: `${baseUrl}/guardian/${encodeURIComponent(state.guardianId)}/admission-details`,
            dataType: 'json',
        }).done((response) => {
            const guardian = response && response.data;
            if (currentGeneration !== state.generation || selectedId() !== state.guardianId) return;
            if (!validDto(guardian, state.guardianId)) {
                state.mode = 'empty'; state.guardianId = ''; state.guardian = null;
                clearCanonicalFields(); $search.attr('data-guardian-admission-controller-state', 'failed'); showError('Unable to load the selected Guardian. Please select it again.');
                return;
            }
            state.guardian = guardian;
            applyStateToForm();
            $search.attr('data-guardian-admission-controller-state', 'existing-ready');
            // Run after Select2 has completed its select/change/close cycle.
            // This remains one authoritative renderer, not a second data path.
            window.setTimeout(() => {
                if (state.mode === 'existing' && state.guardian && selectedId() === state.guardianId) applyStateToForm();
            }, 0);
        }).fail((_xhr, status) => {
            if (status === 'abort' || currentGeneration !== state.generation) return;
            state.mode = 'empty'; state.guardianId = ''; state.guardian = null;
            clearCanonicalFields(); $search.attr('data-guardian-admission-controller-state', 'failed'); showError('Unable to load the selected Guardian. Please try again.');
        }).always(() => {
            if (currentGeneration === state.generation) setBusy(false);
            if (state.request === request) state.request = null;
        });
        state.request = request;
    };
    const selectionChanged = (event) => {
        // Select2 supplies the stable id with select2:select. Prefer it over
        // the source value during the event itself; some Select2 versions do
        // not settle the native value until their subsequent change event.
        const eventId = event && event.params && event.params.data ? field(event.params.data.id) : '';
        const id = eventId || selectedId();
        if (!id) return clearState();
        const tagEmail = isEmail(eventId) ? eventId : selectedTagEmail();
        if (tagEmail && isEmail(tagEmail)) {
            if (state.mode !== 'new' || state.email !== tagEmail) selectNewGuardian(tagEmail);
            return;
        }
        if (state.mode !== 'existing' || state.guardianId !== id) selectExistingGuardian(id);
    };
    const prepareForSubmit = (event) => {
        if (state.mode === 'existing') {
            if (!state.guardian || selectedId() !== state.guardianId || !validDto(state.guardian, state.guardianId)) {
                showError('The selected Guardian is still loading. Please wait and try again.');
                event.preventDefault();
                return;
            }
            applyStateToForm();
            updateDiagnosticState();
            return;
        }
        if (state.mode === 'new' && isEmail(state.email) && selectedTagEmail() === state.email) {
            applyStateToForm();
            updateDiagnosticState();
            return;
        }
        showError('Select an existing Guardian or enter a new Guardian email before submitting.');
        event.preventDefault();
    };

    $search.select2({
        tags: true, allowClear: true, placeholder: 'Search for Guardian Email', minimumInputLength: 1,
        ajax: {
            url: `${baseUrl}/guardian/search`, dataType: 'json', delay: 250, cache: true,
            data: (params) => ({ email: params.term, page: params.page }),
            processResults: (response) => ({
                results: (Array.isArray(response && response.data) ? response.data : []).map((guardian) => ({
                    id: field(guardian.id), text: field(guardian.email) || `${field(guardian.first_name)} ${field(guardian.last_name)}`.trim(),
                })),
            }),
        },
    });
    // Bind the source directly and delegate as a Select2 lifecycle fallback.
    // The guard in selectionChanged makes a duplicate select/change harmless.
    // Namespace only this page controller; the shared legacy Guardian widget
    // remains untouched for other pages.
    $search
        .off('select2:select.guardianAdmissionController change.guardianAdmissionController')
        .on('select2:select.guardianAdmissionController change.guardianAdmissionController', selectionChanged);
    $(document)
        .off('select2:select.guardianAdmissionController change.guardianAdmissionController', '#guardian_admission_guardian_id')
        .on('select2:select.guardianAdmissionController change.guardianAdmissionController', '#guardian_admission_guardian_id', selectionChanged);
    // Select2 tags can commit a newly typed option while closing the dropdown
    // without dispatching a source change event. Re-read the settled selection
    // at close so the canonical new-Guardian state is never left empty.
    $search.on('select2:close.guardianAdmissionController', () => window.setTimeout(selectionChanged, 0));
    $search.on('select2:clear.guardianAdmissionController', clearState);
    $('input[name="guardian_gender_display"]').on('change.guardianAdmissionController', function () {
        if (state.mode !== 'existing') $canonical.gender.val(this.value);
    });
    $form.on('reset.guardianAdmissionController', () => window.setTimeout(() => {
        $search.val(null).trigger('change.select2'); clearState();
    }, 0));
    // jQuery validation may reject required Guardian fields before bubbling
    // submit handlers run. Capture phase restores canonical existing-Guardian
    // data before validation; the cancelable FormData hook remains the final
    // guard immediately before common.js serializes the request.
    $form[0].addEventListener('submit', prepareForSubmit, true);
    $form[0].addEventListener('eschool:before-form-data', prepareForSubmit);
});
