"use strict";

// Student Admission has one Guardian controller. Select2 chooses only an id;
// existing Guardian details always come from the tenant-scoped endpoint.
$(function () {
    const $search = $('#guardian_admission_guardian_id');
    const $form = $('#create-form');
    const $submit = $('#create-btn');

    if (!$search.length || !$form.length || !$search.data('guardianAdmissionController')) return;

    const state = { mode: 'empty', guardianId: '', guardian: null, request: null, generation: 0 };
    const field = (value) => value == null ? '' : String(value).trim();
    const isEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    const $canonical = {
        mode: $('#guardian_mode'), id: $('#guardian_id'), email: $('#guardian_email'),
        firstName: $('#guardian_first_name'), lastName: $('#guardian_last_name'),
        mobile: $('#guardian_mobile'), gender: $('#guardian_gender'),
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
        $canonical.mode.val('empty');
        $canonical.id.val('');
        $canonical.email.val('');
        $canonical.firstName.val('').prop('readonly', false);
        $canonical.lastName.val('').prop('readonly', false);
        $canonical.mobile.val('').prop('readonly', false);
        $canonical.gender.val('male');
        setDisplayGender('male', false);
        clearImage();
    };
    const applyStateToForm = () => {
        $canonical.mode.val(state.mode);
        if (state.mode === 'existing') {
            const guardian = state.guardian;
            $canonical.id.val(field(guardian.id));
            $canonical.email.val(field(guardian.email));
            $canonical.firstName.val(field(guardian.first_name)).prop('readonly', true);
            $canonical.lastName.val(field(guardian.last_name)).prop('readonly', true);
            $canonical.mobile.val(field(guardian.mobile)).prop('readonly', true);
            $canonical.gender.val(field(guardian.gender));
            setDisplayGender(field(guardian.gender), true);
            clearImage();
            $('#guardian-image-preview').attr('src', field(guardian.image));
            $('#guardian_image').siblings('span').find('button').prop('disabled', true);
            return;
        }
        if (state.mode === 'new') {
            $canonical.id.val('');
            $canonical.email.val(field(state.email));
            $canonical.firstName.prop('readonly', false);
            $canonical.lastName.prop('readonly', false);
            $canonical.mobile.prop('readonly', false);
            $canonical.gender.val(displayGender());
            setDisplayGender(displayGender(), false);
            return;
        }
        clearCanonicalFields();
    };
    const clearState = () => {
        abortPending();
        state.mode = 'empty'; state.guardianId = ''; state.guardian = null; delete state.email;
        clearError(); setBusy(false); clearCanonicalFields();
    };
    const selectNewGuardian = (email) => {
        abortPending();
        state.mode = 'new'; state.guardianId = ''; state.guardian = null; state.email = email;
        clearError(); clearCanonicalFields(); applyStateToForm(); setBusy(false);
    };
    const selectExistingGuardian = (id) => {
        abortPending();
        const currentGeneration = state.generation;
        state.mode = 'existing'; state.guardianId = field(id); state.guardian = null; delete state.email;
        clearError(); clearCanonicalFields(); $canonical.mode.val('existing'); $canonical.id.val(state.guardianId); setBusy(true);
        const request = $.ajax({
            url: `${baseUrl}/guardian/${encodeURIComponent(state.guardianId)}/admission-details`,
            dataType: 'json',
        }).done((response) => {
            const guardian = response && response.data;
            if (currentGeneration !== state.generation || selectedId() !== state.guardianId) return;
            if (!validDto(guardian, state.guardianId)) {
                state.mode = 'empty'; state.guardianId = ''; state.guardian = null;
                clearCanonicalFields(); showError('Unable to load the selected Guardian. Please select it again.');
                return;
            }
            state.guardian = guardian;
            applyStateToForm();
        }).fail((_xhr, status) => {
            if (status === 'abort' || currentGeneration !== state.generation) return;
            state.mode = 'empty'; state.guardianId = ''; state.guardian = null;
            clearCanonicalFields(); showError('Unable to load the selected Guardian. Please try again.');
        }).always(() => {
            if (currentGeneration === state.generation) setBusy(false);
            if (state.request === request) state.request = null;
        });
        state.request = request;
    };
    const selectionChanged = () => {
        const id = selectedId();
        if (!id) return clearState();
        const tagEmail = selectedTagEmail();
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
            return;
        }
        if (state.mode === 'new' && isEmail(state.email) && selectedTagEmail() === state.email) {
            applyStateToForm();
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
    $search.on('select2:select.guardianAdmissionController change.guardianAdmissionController', selectionChanged);
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
