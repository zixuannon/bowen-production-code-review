"use strict";

// Student Admission owns this selector. Select2 supplies only a Guardian id;
// all existing-Guardian fields come from the tenant-scoped canonical endpoint.
$(function () {
    const $search = $('#guardian_admission_guardian_id');
    const $form = $('#create-form');
    const $submit = $('#create-btn');

    if (!$search.length || !$form.length || !$search.data('guardianAdmissionController')) return;

    let request = null;
    let generation = 0;
    let state = null;

    const field = (value) => value == null ? '' : String(value).trim();
    const selectedId = () => field($search.val());
    const isNewGuardianEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    const validGuardian = (guardian, expectedId) => Boolean(
        guardian && field(guardian.id) === expectedId && field(guardian.email)
        && field(guardian.first_name) && field(guardian.last_name) && field(guardian.mobile)
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
    const clearFields = () => {
        $('#guardian_email').val('');
        $('#guardian_first_name,#guardian_last_name,#guardian_mobile').val('').prop('readonly', false);
        $('#guardian-image-preview').attr('src', '');
        $('#guardian_image').siblings('span').find('button').prop('disabled', false);
        $('#guardian_male,#guardian_female').prop('disabled', false);
    };
    const syncSelectedGuardian = (guardian) => {
        $('#guardian_email').val(field(guardian.email));
        $('#guardian_first_name').val(field(guardian.first_name)).prop('readonly', true);
        $('#guardian_last_name').val(field(guardian.last_name)).prop('readonly', true);
        $('#guardian_mobile').val(field(guardian.mobile)).prop('readonly', true);
        $('#guardian_male').prop('checked', guardian.gender === 'male').prop('disabled', true);
        $('#guardian_female').prop('checked', guardian.gender !== 'male').prop('disabled', true);
        $('#guardian-image-preview').attr('src', field(guardian.image));
        $('#guardian_image').siblings('span').find('button').prop('disabled', true);
    };
    const clearSelection = () => {
        generation += 1;
        if (request) request.abort();
        request = null;
        state = null;
        setBusy(false);
        clearError();
        clearFields();
    };
    const loadGuardian = (guardianId) => {
        const id = field(guardianId);
        if (!id) return clearSelection();

        if (isNewGuardianEmail(id)) {
            state = { id, status: 'new' };
            clearError();
            clearFields();
            $('#guardian_email').val(id);
            setBusy(false);
            return;
        }

        generation += 1;
        const currentGeneration = generation;
        if (request) request.abort();
        request = null;
        state = { id, status: 'loading' };
        clearError();
        clearFields();
        setBusy(true);

        const currentRequest = $.ajax({
            url: `${baseUrl}/guardian/${encodeURIComponent(id)}/admission-details`,
            dataType: 'json',
        }).done((response) => {
            const guardian = response && response.data;
            if (currentGeneration !== generation || selectedId() !== id) return;
            if (!validGuardian(guardian, id)) {
                state = { id, status: 'invalid' };
                showError('Unable to load the selected Guardian. Please select it again.');
                return;
            }
            state = { id, status: 'ready', guardian };
            syncSelectedGuardian(guardian);
        }).fail((_xhr, status) => {
            if (status === 'abort' || currentGeneration !== generation || selectedId() !== id) return;
            state = { id, status: 'failed' };
            showError('Unable to load the selected Guardian. Please try again.');
        }).always(() => {
            if (currentGeneration === generation) setBusy(false);
            if (request === currentRequest) request = null;
        });
        request = currentRequest;
    };
    const synchronizeForSubmit = () => {
        const id = selectedId();
        if (!id) return true;
        if (state && state.status === 'new' && state.id === id) {
            $('#guardian_email').val(id);
            return true;
        }
        if (!state || state.status !== 'ready' || state.id !== id || !validGuardian(state.guardian, id)) {
            showError('The selected Guardian is still loading. Please wait and try again.');
            return false;
        }
        syncSelectedGuardian(state.guardian);
        return true;
    };

    $search.select2({
        tags: true,
        allowClear: true,
        placeholder: 'Search for Guardian Email',
        minimumInputLength: 1,
        ajax: {
            url: `${baseUrl}/guardian/search`,
            dataType: 'json',
            delay: 250,
            cache: true,
            data: (params) => ({ email: params.term, page: params.page }),
            processResults: (response) => ({
                results: (Array.isArray(response && response.data) ? response.data : []).map((guardian) => ({
                    id: field(guardian.id),
                    text: field(guardian.email) || `${field(guardian.first_name)} ${field(guardian.last_name)}`.trim(),
                })),
            }),
        },
    });

    $search.on('select2:select.guardianAdmissionController', (event) => {
        // Select2 event data is accepted only for its stable id. The canonical
        // Guardian fields still always come from admission-details.
        loadGuardian(field(event && event.params && event.params.data && event.params.data.id) || selectedId());
    });
    $search.on('select2:clear.guardianAdmissionController', clearSelection);
    $search.on('change.guardianAdmissionController', () => {
        const id = selectedId();
        if (!id) clearSelection();
        else if (!state || state.id !== id) loadGuardian(id);
    });
    $form.on('reset.guardianAdmissionController', () => window.setTimeout(() => {
        $search.val(null).trigger('change.select2');
        clearSelection();
    }, 0));
    $form[0].addEventListener('submit', (event) => {
        if (!synchronizeForSubmit()) event.preventDefault();
    }, true);
});
