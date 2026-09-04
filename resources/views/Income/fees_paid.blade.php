@extends('layouts.master')

@section('title')
    {{ __('manage') . ' ' . __('fees') }} {{ __('paid') }}
@endsection

@section('content')
    <div class="content-wrapper">
        @if(app(\App\Services\CentralFinanceSchoolFinanceNavigationService::class)->usesCentralFinanceDailyWorkspace())
            @include('components.central-finance-legacy-historical-notice')
        @endif
        <div class="page-header">
            <h3 class="page-title">
                {{ __('manage') . ' ' . __('fees') }} {{ __('paid') }}
            </h3>
            <nav aria-label="breadcrumb">
                <button type="button" class="btn btn-theme btn-sm float-right" id="btn-import-excel"
                    onclick="openImportModal()">
                    <i class="fa fa-upload mr-1"></i> {{ __('Import Excel') }}
                </button>
            </nav>
        </div>
        <div class="row">
            {{-- Total Fees --}}
            <div class="col-md-4 col-sm-12 grid-margin stretch-card">
                <div class="card card-statistics">
                    <div class="custom-card-body">
                        <div class="row">
                            <div class="col-sm-12 col-md-6">
                                <p class="font-weight-bold">{{ __('total_fees') }}</p>
                                <div class="d-flex align-items-center">
                                    <h4 class="font-weight-semibold total_fees_statistics">0</h4>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6 border-left text-right">
                                <p class="text-muted mt-2">{{ __('compulsory_fees') }} : <span
                                        class="total_compulsory_fees">0</span></p>
                                <p class="text-muted mb-0">{{ __('optional_fees') }} : <span
                                        class="total_optional_fees">0</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Total Collected Fees --}}
            <div class="col-md-4 col-sm-12 grid-margin stretch-card">
                <div class="card card-statistics">
                    <div class="custom-card-body">
                        <div class="row">
                            <div class="col-sm-12 col-md-6">
                                <p class="font-weight-bold"> {{ __('collected') }} {{ __('Fees') }}</p>
                                <div class="d-flex align-items-center">
                                    <h4 class="font-weight-semibold total_fees_collected">0</h4>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6 border-left text-right">
                                <p class="text-muted mt-2">{{ __('compulsory_fees') }} : <span
                                        class="total_compulsory_fees_collected">0</span></p>
                                <p class="text-muted mb-0">{{ __('optional_fees') }} : <span
                                        class="total_optional_fees_collected">0</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Total Pending Fees --}}
            <div class="col-md-4 col-sm-12 grid-margin stretch-card">
                <div class="card card-statistics">
                    <div class="custom-card-body">
                        <div class="row">
                            <div class="col-sm-12 col-md-6">
                                <p class="font-weight-bold"> {{ __('pending') }} {{ __('Fees') }}</p>
                                <div class="d-flex align-items-center">
                                    <h4 class="font-weight-semibold total_fees_pending">0</h4>
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6 border-left text-right">
                                <p class="text-muted mt-2">{{ __('compulsory_fees') }} : <span
                                        class="total_compulsory_fees_pending">0</span></p>
                                <p class="text-muted mb-0">{{ __('optional_fees') }} : <span
                                        class="total_optional_fees_pending">0</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-12 grid-margin stretch-card search-container">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title"></h4>
                        <div id="toolbar">
                            <div class="row">
                                <div class="form-group col-md-4">
                                    <label class="filter-menu" for="session_year_id"> {{ __('Session Years') }} </label>
                                    <select name="session_year_id" id="session_year_id" class="form-control">
                                        @foreach ($session_year_all as $session_year)
                                            <option value="{{ $session_year->id }}"
                                                {{ $session_year->default ? 'selected' : '' }}> {{ $session_year->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group col-md-4">
                                    <label class="filter-menu" for="filter_fees_id">{{ __('Fees') }}</label>
                                    <select name="filter_fees_id" id="filter_fees_id" class="form-control">
                                        @foreach ($fees as $key => $fee)
                                            <option value="{{ $fee->id }}" data-class-section-id="{{ $fee->class_id }}" {{ $key == 0 ? 'selected' : '' }}>
                                                {{ $fee->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-md-4">
                                    <label for="filter-class-section-id"
                                        class="filter-menu">{{ __('Class Section') }}</label>
                                    <select name="filter-class-section-id" id="filter-class-section-id"
                                        class="form-control">
                                        <option value="">{{ __('all') }}</option>
                                        @foreach ($class_section as $class)
                                            <option value="{{ $class->id }}" data-class-section-id="{{ $class->class_id }}">
                                                {{ $class->full_name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-md-3">
                                    <label class="filter-menu" for="filter_paid_status"> {{ __('status') }} </label>
                                    <select name="filter_paid_status" id="filter_paid_status" class="form-control">
                                        <option value="0">{{ __('unpaid') }}</option>
                                        <option value="1">{{ __('paid') }}</option>
                                        <option value="2">{{ __('Partial Paid') }}</option>
                                        
                                    </select>
                                </div>
                            </div>

                            {{-- Paid filter --}}
                            <div class="row paid-filter" style="display: none">
                                <div class="form-group col-md-3">
                                    <label class="filter-menu" for="filter_paid_status"> {{ __('month') }} </label>
                                    {!! Form::select('month', $months, date('n'), ['class' => 'form-control paid-month','placeholder' => __('all')]) !!}
                                </div>

                                {{-- <div class="form-group col-md-3">
                                    <label for="filter_gateway" class="filter-menu">{{ __('payment_type') }}</label>
                                    {!! Form::select('payment_type', ['' => __('All'), 'cash_cheque' => __('cash_cheque'),'stripe_razorpay' => __('stripe_razorpay')], 0, ['class' => 'form-control payment-gateway' ,'id' => 'filter_gateway']) !!}
                                </div> --}}
                                
                                <div class="form-group col-md-3">
                                    <label for="filter_online_offline_payment" class="filter-menu">{{ __('online_offline_payment') }}</label>
                                    <select name="filter_online_offline_payment" id="filter_online_offline_payment" class="form-control select2">
                                        <option value="0">{{ __('all') }}</option>
                                        <option value="1">{{ __('online') }}</option>
                                        <option value="2">{{ __('offline') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                            data-url="{{ route('fees.paid.list', 1) }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100, 200]"
                            data-search="true" data-toolbar="#toolbar" data-show-columns="true" data-show-refresh="true"
                            data-fixed-columns="false" data-trim-on-search="false" data-mobile-responsive="true"
                            data-sort-name="id" data-sort-order="desc" data-maintain-selected="true"
                            data-export-data-type='all'
                            data-export-options='{ "fileName": "{{ __('fees') }}-{{ __('paid') }}-{{ __('list') }}-<?= date('d-m-y')
                            ?>" ,"ignoreColumn":["operate"]}'
                            data-show-export="true" data-query-params="feesPaidListQueryParams" data-escape="true">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false" data-align="center">{{ __('id') }}</th>
                                    <th scope="col" data-field="no" data-formatter="totalFeesFormatter" data-sortable="false" data-align="center">{{ __('no.') }}</th>
                                    <th scope="col" data-field="student.id" data-sortable="false" data-visible="false" data-align="center">{{ __('Student Id') }}</th>
                                    <th scope="col" data-field="full_name" data-formatter="NotificationUserNameFormatter" data-sortable="false"> {{ __('Student Name') }}</th>
                                    <th scope="col" data-field="student.class_section.full_name" data-sortable="false" data-align="center">{{ __('Class') }}</th>
                                    <th scope="col" data-field="fees.total_compulsory_fees" data-sortable="false" data-align="center" data-formatter="feesCurrencyAmountFormatter">{{ __('Compulsory Fees') }}</th>
                                    <th scope="col" data-field="fees.total_optional_fees" data-sortable="false" data-align="center" data-formatter="feesCurrencyAmountFormatter">{{ __('Optional Fees') }}</th>
                                    <th scope="col" data-field="payment_method" data-sortable="false" data-align="center"> {{ __('Payment Method') }}</th>
                                    <th scope="col" data-field="fees_status" data-sortable="false" data-formatter="feesPaidStatusFormatter" data-align="center"> {{ __('Fees Status') }}</th>
                                    <th scope="col" data-field="fees_paid.date"  data-sortable="false" data-align="center">{{ __('Date') }}</th>
                                    <th scope="col" data-field="paid_amount" data-sortable="false" data-formatter="feesPaidAmountFormatter">{{ __('paid_amount') }}</th>
                                    <th scope="col" data-field="operate" data-sortable="false" data-events="feesPaidEvents" data-align="center" data-escape="false"> {{ __('Action') }}</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============ Import Excel Modal ============ --}}
    <div class="modal fade" id="importExcelModal" tabindex="-1" role="dialog" aria-labelledby="importExcelLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Import Fees Paid (Excel)') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="closeImportModal()">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">

                    {{-- Step 1: Upload --}}
                    <div id="import-step-upload">
                        <div class="text-muted mb-3">
                            <i class="fa fa-info-circle"></i>
                            {{ __('Download template first, fill data, then upload. Max 500 rows. Max file size: 5MB.') }}
                        </div>
                        <div class="mb-3">
                            <a href="{{ route('fees.import.template') }}" class="btn btn-outline-theme btn-sm" id="btn-download-template">
                                <i class="fa fa-download"></i> {{ __('Download Template') }}
                            </a>
                        </div>
                        <div class="custom-file mb-3">
                            <input type="file" class="custom-file-input" id="import-file-input" accept=".xlsx,.xls,.csv">
                            <label class="custom-file-label" for="import-file-input">{{ __('Choose Excel file...') }}</label>
                        </div>
                        <div id="import-file-info" class="text-muted small mb-2" style="display:none;">
                            <span id="import-file-name"></span> (<span id="import-file-size"></span>)
                        </div>
                        <div class="form-group">
                            <button type="button" class="btn btn-theme" id="btn-upload-preview"
                                onclick="uploadForPreview()" disabled>
                                <i class="fa fa-search"></i> {{ __('Preview') }}
                            </button>
                            <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="closeImportModal()">{{ __('Cancel') }}</button>
                        </div>
                        <div id="import-upload-error" class="alert alert-danger" style="display:none;"></div>
                    </div>

                    {{-- Step 2: Preview --}}
                    <div id="import-step-preview" style="display:none;">
                        <div class="mb-3">
                            <strong>{{ __('Batch Token') }}:</strong>
                            <code id="import-token" class="text-break"></code>
                            <span id="import-token-expiry" class="text-muted small ml-2"></span>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-3"><span class="badge badge-secondary" id="imp-total">0 Total</span></div>
                            <div class="col-md-3"><span class="badge badge-success" id="imp-valid">0 Valid</span></div>
                            <div class="col-md-3"><span class="badge badge-warning" id="imp-duplicate">0 Duplicate</span></div>
                            <div class="col-md-3"><span class="badge badge-danger" id="imp-error">0 Error</span></div>
                        </div>
                        <div style="max-height:350px; overflow-y:auto;">
                            <table class="table table-sm table-bordered" id="import-preview-table">
                                <thead>
                                    <tr>
                                        <th>#</th><th>{{ __('Status') }}</th><th>{{ __('Student') }}</th>
                                        <th>{{ __('Reference No') }}</th><th>{{ __('Amount') }}</th>
                                        <th>{{ __('Mode') }}</th><th>{{ __('Errors') }}</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div id="import-preview-error-msg" class="alert alert-warning small mt-2" style="display:none;"></div>
                        <div class="form-group mt-3">
                            <button type="button" class="btn btn-success" id="btn-confirm-import"
                                onclick="confirmImport()" disabled>
                                <i class="fa fa-check"></i> {{ __('Confirm Import') }}
                            </button>
                            <button type="button" class="btn btn-outline-secondary"
                                onclick="resetImportModal()">{{ __('Back') }}</button>
                        </div>
                        <div id="import-confirm-error" class="alert alert-danger mt-2" style="display:none;"></div>
                    </div>

                    {{-- Step 3: Result --}}
                    <div id="import-step-result" style="display:none;">
                        <div class="text-center mb-3">
                            <i class="fa fa-check-circle text-success" style="font-size:48px;"></i>
                            <h4 class="mt-2">{{ __('Import Completed') }}</h4>
                        </div>
                        <table class="table table-sm table-bordered">
                            <tr><td>{{ __('Batch ID') }}</td><td id="result-batch-id"></td></tr>
                            <tr><td>{{ __('Imported') }}</td><td id="result-imported" class="text-success font-weight-bold"></td></tr>
                            <tr><td>{{ __('Skipped') }}</td><td id="result-skipped" class="text-warning font-weight-bold"></td></tr>
                            <tr><td>{{ __('Total Rows') }}</td><td id="result-total"></td></tr>
                        </table>
                        <div class="form-group text-center">
                            <button type="button" class="btn btn-theme" data-dismiss="modal" onclick="closeImportModal()">{{ __('Close') }}</button>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

@endsection
@section('js')
    <script>
        // ================================================================
        // Import state
        // ================================================================
        let importToken = null;
        let importHasErrors = false;
        let importIsConfirming = false;

        function openImportModal() {
            resetImportModal();
            $('#importExcelModal').modal('show');
        }

        function closeImportModal() {
            $('#importExcelModal').modal('hide');
            if (importToken) {
                // Refresh fee list after import
                $('#table_list').bootstrapTable('refresh');
            }
            resetImportModal();
        }

        function resetImportModal() {
            importToken = null;
            importHasErrors = false;
            importIsConfirming = false;
            $('#import-step-upload').show();
            $('#import-step-preview').hide();
            $('#import-step-result').hide();
            $('#import-file-input').val('');
            $('.custom-file-label').text('Choose Excel file...');
            $('#import-file-info').hide();
            $('#btn-upload-preview').prop('disabled', true);
            $('#import-upload-error').hide().text('');
            $('#import-preview-table tbody').empty();
            $('#import-preview-error-msg').hide();
            $('#btn-confirm-import').prop('disabled', true).html('<i class="fa fa-check"></i> Confirm Import');
            $('#import-confirm-error').hide().text('');
            $('#import-upload-error').hide();
        }

        // ---- File input handler ----
        $('#import-file-input').on('change', function() {
            const file = this.files[0];
            if (file) {
                $('.custom-file-label').text(file.name);
                $('#import-file-name').text(file.name);
                $('#import-file-size').text(formatFileSize(file.size));
                $('#import-file-info').show();

                // Validate size
                if (file.size > 5 * 1024 * 1024) {
                    $('#import-upload-error').text('File size exceeds 5MB limit').show();
                    $('#btn-upload-preview').prop('disabled', true);
                } else {
                    $('#import-upload-error').hide();
                    $('#btn-upload-preview').prop('disabled', false);
                }
            } else {
                $('.custom-file-label').text('Choose Excel file...');
                $('#import-file-info').hide();
                $('#btn-upload-preview').prop('disabled', true);
            }
        });

        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }

        // ---- Upload for Preview ----
        function uploadForPreview() {
            const fileInput = $('#import-file-input')[0];
            if (!fileInput.files.length) return;

            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('file', file);

            const $btn = $('#btn-upload-preview');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Uploading...');
            $('#import-upload-error').hide();

            $.ajax({
                url: '{{ route('fees.import.preview') }}',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    if (response.error) {
                        showUploadError(response.message);
                        return;
                    }
                    renderPreview(response.data);
                },
                error: function(xhr) {
                    const msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Upload failed. Please check the file format.';
                    showUploadError(msg);
                }
            });
        }

        function showUploadError(msg) {
            $('#import-upload-error').text(msg).show();
            $('#btn-upload-preview').prop('disabled', false).html('<i class="fa fa-search"></i> Preview');
        }

        // ---- Render Preview ----
        function renderPreview(data) {
            importToken = data.token;
            importHasErrors = (data.summary.error > 0 || data.summary.duplicate > 0);

            $('#import-step-upload').hide();
            $('#import-step-preview').show();

            $('#import-token').text(data.token);

            // Token expiry
            const now = new Date();
            const expiry = new Date(now.getTime() + 30 * 60 * 1000);
            $('#import-token-expiry').text('(expires at ' + expiry.toLocaleTimeString() + ')');

            // Summary badges
            $('#imp-total').text(data.summary.total + ' Total');
            $('#imp-valid').text(data.summary.valid + ' Valid');
            $('#imp-duplicate').text(data.summary.duplicate + ' Duplicate');
            $('#imp-error').text(data.summary.error + ' Error');

            // Rows
            const $tbody = $('#import-preview-table tbody');
            $tbody.empty();
            data.rows.forEach(function(row) {
                let statusBadge;
                if (row.status === 'valid') {
                    statusBadge = '<span class="badge badge-success">Valid</span>';
                } else if (row.status === 'duplicate') {
                    statusBadge = '<span class="badge badge-warning">Duplicate</span>';
                } else {
                    statusBadge = '<span class="badge badge-danger">Error</span>';
                }

                const errors = (row.errors && row.errors.length)
                    ? row.errors.join('; ')
                    : (row.warnings && row.warnings.length ? row.warnings.join('; ') : '');

                $tbody.append(
                    '<tr>' +
                    '<td>' + row.row_number + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + (row.student_name || '-') + '</td>' +
                    '<td>' + (row.reference_no || '-') + '</td>' +
                    '<td>' + (row.payment_data && row.payment_data.enter_amount ? row.payment_data.enter_amount : '-') + '</td>' +
                    '<td>' + (row.payment_mode || '-') + '</td>' +
                    '<td class="text-danger small">' + (errors ? escapeHtml(errors) : '-') + '</td>' +
                    '</tr>'
                );
            });

            // Error rows > 0 => disable Confirm
            if (data.summary.error > 0) {
                $('#btn-confirm-import').prop('disabled', true);
                $('#import-preview-error-msg')
                    .text('Error rows present. Fix errors and re-upload before confirming.')
                    .show();
            } else {
                $('#btn-confirm-import').prop('disabled', false);
                $('#import-preview-error-msg').hide();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ---- Confirm Import ----
        function confirmImport() {
            if (importIsConfirming) return;
            if (!importToken) return;

            importIsConfirming = true;
            const $btn = $('#btn-confirm-import');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Confirming...');
            $('#import-confirm-error').hide();

            $.ajax({
                url: '{{ route('fees.import.confirm') }}',
                type: 'POST',
                data: { token: importToken },
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    if (response.error) {
                        showConfirmError(response.message);
                        return;
                    }
                    renderResult(response.data);
                },
                error: function(xhr) {
                    importIsConfirming = false;
                    let msg = 'Confirmation failed.';
                    if (xhr.status === 422) {
                        // Token expired / completed / failed / cross-user / cross-school
                        msg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Batch is no longer valid. Please re-upload the file.';
                    } else if (xhr.status === 500) {
                        msg = (xhr.responseJSON && xhr.responseJSON.message)
                            ? xhr.responseJSON.message
                            : 'Internal server error. Please try again.';
                    }
                    showConfirmError(msg);
                }
            });
        }

        function showConfirmError(msg) {
            importIsConfirming = false;
            const $btn = $('#btn-confirm-import');
            // If batch has moved to a terminal state, disable permanently
            if (msg.toLowerCase().indexOf('expired') >= 0 ||
                msg.toLowerCase().indexOf('completed') >= 0 ||
                msg.toLowerCase().indexOf('failed') >= 0 ||
                msg.toLowerCase().indexOf('processing') >= 0) {
                $btn.prop('disabled', true).html('Cannot Confirm');
            } else {
                $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Confirm Import');
            }
            $('#import-confirm-error').text(msg).show();
        }

        // ---- Show Result ----
        function renderResult(data) {
            importIsConfirming = false;
            $('#import-step-preview').hide();
            $('#import-step-result').show();
            $('#result-batch-id').text(data.batch_id || '-');
            $('#result-imported').text(data.imported || 0);
            $('#result-skipped').text(data.skipped || 0);
            $('#result-total').text(data.total_rows || 0);
        }

        // ================================================================
        // Existing Filters (unchanged)
        // ================================================================

        $('#filter_paid_status').change(function (e) { 
            e.preventDefault();
            $('.paid-filter').hide(500);

            if ($(this).val() == 1 || $(this).val() == 2) {
                $('.paid-filter').show(500);
            }
        });

        window.onload = setTimeout(() => {
            $('#session_year_id').trigger('change');
        }, 500);

        $('#session_year_id').on('change', function() {
            let data = new FormData();
            data.append('session_year_id', $(this).val());
            ajaxRequest('GET', baseUrl + '/fees/search', {
                'session_year_id': $(this).val()
            }, null, function(response) {
                let feesDropdown = "";
                response.data.forEach(function(value, index) {
                    feesDropdown += "<option value='" + value.id + "' data-class-section-id='" + value.class_id + "'>" + value.name + "</option>";
                })

                $('#filter_fees_id').html(feesDropdown);
                $('#table_list').bootstrapTable('refresh');
            }, null, null, true)
        })
    </script>
@endsection
