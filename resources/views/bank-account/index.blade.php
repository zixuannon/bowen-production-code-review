@extends('layouts.master')

@section('title')
    {{ __('Bank Accounts') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Bank Accounts') }}
            </h3>
        </div>

        {{-- Create Form --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Add New Bank Account') }}</h4>
                        <form class="pt-3" id="create-form" novalidate="novalidate">
                            @csrf
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('Account Name') }} <span class="text-danger">*</span></label>
                                    <input name="account_name" id="account_name" type="text" class="form-control"
                                        placeholder="{{ __('e.g. KBZ Main Account') }}" required maxlength="255" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Account Number') }}</label>
                                    <input name="account_number" id="account_number" type="text" class="form-control"
                                        placeholder="{{ __('Account No.') }}" maxlength="100" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Bank Name') }}</label>
                                    <input name="bank_name" id="bank_name" type="text" class="form-control"
                                        placeholder="{{ __('e.g. KBZ, AYA, CB') }}" maxlength="255" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Account Type') }} <span class="text-danger">*</span></label>
                                    {!! Form::select('account_type', $accountTypes, null, [
                                        'required',
                                        'class' => 'form-control',
                                        'placeholder' => __('Select Type'),
                                    ]) !!}
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Currency') }} <span class="text-danger">*</span></label>
                                    <select name="currency" id="currency" class="form-control" required>
                                        <option value="MMK" selected>MMK</option>
                                        <option value="USD">USD</option>
                                        <option value="CNY">CNY</option>
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="opening_balance">{{ __('Opening Balance') }}</label>
                                    <input name="opening_balance" id="opening_balance" type="number" min="0" step="0.01"
                                        value="0" class="form-control" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label for="opening_balance_date">{{ __('Opening Balance Date') }}</label>
                                    <input name="opening_balance_date" id="opening_balance_date" type="text"
                                        class="datepicker-popup-no-future form-control" autocomplete="off" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Notes') }}</label>
                                    <input name="notes" id="notes" type="text" class="form-control"
                                        placeholder="{{ __('Optional notes') }}" maxlength="1000" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2 d-flex align-items-end">
                                    <div class="form-check mr-3">
                                        <label class="form-check-label">
                                            <input type="checkbox" name="is_active" class="form-check-input" checked />
                                            {{ __('Active') }}
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="checkbox" name="is_default" class="form-check-input" />
                                            {{ __('Set as Default') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <input class="btn btn-theme float-right ml-3" id="create-btn" type="submit"
                                value="{{ __('submit') }}">
                            <input class="btn btn-secondary float-right" type="reset" value="{{ __('reset') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- List Table --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Bank Account List') }}</h4>

                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                            data-url="{{ route('bank-accounts.list') }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100]"
                            data-search="true" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true"
                            data-sort-name="id" data-sort-order="desc"
                            data-show-export="true"
                            data-export-options='{ "fileName": "bank-accounts-<?= date('d-m-y') ?>" ,"ignoreColumn":["operate"]}'
                            data-escape="true">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('ID') }}</th>
                                    <th scope="col" data-field="no">{{ __('No.') }}</th>
                                    <th scope="col" data-field="account_name" data-sortable="true"
                                        data-formatter="accountNameLinkFormatter">{{ __('Account Name') }}</th>
                                    <th scope="col" data-field="bank_name" data-sortable="true">{{ __('Bank Name') }}</th>
                                    <th scope="col" data-field="account_number">{{ __('Account Number') }}</th>
                                    <th scope="col" data-field="account_type_name" data-sortable="true">{{ __('Type') }}</th>
                                    <th scope="col" data-field="currency" data-sortable="true">{{ __('Currency') }}</th>
                                    <th scope="col" data-field="opening_balance" data-sortable="true"
                                        data-formatter="balanceFormatter">{{ __('Opening Balance') }}</th>
                                    <th scope="col" data-field="income_total"
                                        data-formatter="balanceFormatter">{{ __('Income Total') }}</th>
                                    <th scope="col" data-field="expense_total"
                                        data-formatter="balanceFormatter">{{ __('Expense Total') }}</th>
                                    <th scope="col" data-field="current_balance" data-sortable="true"
                                        data-formatter="balanceFormatter">{{ __('Current Balance') }}</th>
                                    <th scope="col" data-field="status_badge" data-escape="false">{{ __('Status') }}</th>
                                    <th scope="col" data-field="default_badge" data-escape="false">{{ __('Default') }}</th>
                                    <th scope="col" data-field="operate" data-events="bankAccountEvents" data-escape="false">
                                        {{ __('Action') }}
                                    </th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Edit Modal --}}
        <div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-labelledby="editModalLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('Edit Bank Account') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form class="pt-3 edit-form" id="edit-form" novalidate="novalidate">
                        @csrf
                        @method('PUT')
                        <div class="modal-body">
                            <input type="hidden" name="edit_id" id="edit_id" />
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Account Name') }} <span class="text-danger">*</span></label>
                                    <input name="edit_account_name" id="edit_account_name" type="text"
                                        class="form-control" required maxlength="255" />
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('Account Number') }}</label>
                                    <input name="edit_account_number" id="edit_account_number" type="text"
                                        class="form-control" maxlength="100" />
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('Bank Name') }}</label>
                                    <input name="edit_bank_name" id="edit_bank_name" type="text"
                                        class="form-control" maxlength="255" />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Account Type') }} <span class="text-danger">*</span></label>
                                    {!! Form::select('edit_account_type', $accountTypes, null, [
                                        'required',
                                        'class' => 'form-control',
                                        'id' => 'edit_account_type',
                                    ]) !!}
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Currency') }} <span class="text-danger">*</span></label>
                                    <select name="edit_currency" id="edit_currency" class="form-control" required>
                                        <option value="MMK">MMK</option>
                                        <option value="USD">USD</option>
                                        <option value="CNY">CNY</option>
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label for="edit_opening_balance">{{ __('Opening Balance') }}</label>
                                    <input name="edit_opening_balance" id="edit_opening_balance" type="number"
                                        min="0" step="0.01" class="form-control" />
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label for="edit_opening_balance_date">{{ __('Opening Balance Date') }}</label>
                                    <input name="edit_opening_balance_date" id="edit_opening_balance_date" type="text"
                                        class="datepicker-popup-no-future form-control" autocomplete="off" />
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('Notes') }}</label>
                                    <input name="edit_notes" id="edit_notes" type="text" class="form-control"
                                        maxlength="1000" />
                                </div>

                                <div class="form-group col-sm-12 col-md-3 d-flex align-items-end">
                                    <div class="form-check mr-3">
                                        <label class="form-check-label">
                                            <input type="checkbox" name="edit_is_active" id="edit_is_active"
                                                class="form-check-input" />
                                            {{ __('Active') }}
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <label class="form-check-label">
                                            <input type="checkbox" name="edit_is_default" id="edit_is_default"
                                                class="form-check-input" />
                                            {{ __('Set as Default') }}
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary"
                                data-dismiss="modal">{{ __('close') }}</button>
                            <input class="btn btn-theme" type="submit" value="{{ __('submit') }}" />
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('js')
    <script>
        // Account name link formatter - clickable to detail page
        function accountNameLinkFormatter(value, row) {
            if (!value) return '-';
            return '<a href="' + '{{ url('bank-accounts') }}/' + row.id + '" class="text-primary font-weight-bold">' + value + '</a>';
        }

        // Balance formatter
        function balanceFormatter(value, row) {
            if (value === null || value === undefined) return '-';
            return parseFloat(value).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // ========== Create Form ==========
        $('#create-form').on('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            // Convert checkboxes to boolean
            formData.set('is_active', $('#create-form input[name="is_active"]').is(':checked') ? '1' : '0');
            formData.set('is_default', $('#create-form input[name="is_default"]').is(':checked') ? '1' : '0');

            $.ajax({
                type: 'POST',
                url: '{{ route('bank-accounts.store') }}',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.error === false) {
                        showSuccessToast(response.message);
                        $('#create-form')[0].reset();
                        $('#table_list').bootstrapTable('refresh');
                    } else {
                        showErrorToast(response.message);
                    }
                },
                error: function(xhr) {
                    handleAjaxError(xhr);
                }
            });
        });

        // ========== Edit: Load data into modal ==========
        window.bankAccountEvents = {
            'click .edit-btn': function(e, value, row) {
                $('#edit_id').val(row.id);
                $('#edit_account_name').val(row.account_name);
                $('#edit_account_number').val(row.account_number || '');
                $('#edit_bank_name').val(row.bank_name || '');
                $('#edit_account_type').val(row.account_type).trigger('change');
                $('#edit_currency').val(row.currency);
                $('#edit_opening_balance').val(row.opening_balance);
                $('#edit_opening_balance_date').val(row.opening_balance_date || '');
                $('#edit_notes').val(row.notes || '');
                $('#edit_is_active').prop('checked', row.is_active == 1 || row.is_active === true);
                $('#edit_is_default').prop('checked', row.is_default == 1 || row.is_default === true);
                $('#editModal').modal('show');
            },
            'click .delete-btn': function(e, value, row) {
                Swal.fire({
                    title: '{{ __('Are you sure?') }}',
                    text: '{{ __('This account will be deactivated.') }}',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: '{{ __('Yes, delete it!') }}',
                    cancelButtonText: '{{ __('Cancel') }}'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            type: 'DELETE',
                            url: '{{ url('bank-accounts') }}/' + row.id,
                            data: {
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                if (response.error === false) {
                                    showSuccessToast(response.message);
                                    $('#table_list').bootstrapTable('refresh');
                                } else {
                                    showErrorToast(response.message);
                                }
                            },
                            error: function(xhr) {
                                handleAjaxError(xhr);
                            }
                        });
                    }
                });
            }
        };

        // ========== Edit Form Submit ==========
        $('#edit-form').on('submit', function(e) {
            e.preventDefault();
            var id = $('#edit_id').val();
            var formData = new FormData();
            formData.append('_method', 'PUT');
            formData.append('account_name', $('#edit_account_name').val());
            formData.append('account_number', $('#edit_account_number').val());
            formData.append('bank_name', $('#edit_bank_name').val());
            formData.append('account_type', $('#edit_account_type').val());
            formData.append('currency', $('#edit_currency').val());
            formData.append('opening_balance', $('#edit_opening_balance').val() || 0);
            formData.append('opening_balance_date', $('#edit_opening_balance_date').val());
            formData.append('notes', $('#edit_notes').val());
            formData.append('is_active', $('#edit_is_active').is(':checked') ? '1' : '0');
            formData.append('is_default', $('#edit_is_default').is(':checked') ? '1' : '0');

            $.ajax({
                type: 'POST',
                url: '{{ url('bank-accounts') }}/' + id,
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.error === false) {
                        showSuccessToast(response.message);
                        $('#editModal').modal('hide');
                        $('#table_list').bootstrapTable('refresh');
                    } else {
                        showErrorToast(response.message);
                    }
                },
                error: function(xhr) {
                    handleAjaxError(xhr);
                }
            });
        });

        // Helper: handle ajax validation errors
        function handleAjaxError(xhr) {
            if (xhr.status === 422) {
                var errors = xhr.responseJSON.errors;
                var msg = '';
                $.each(errors, function(key, val) {
                    msg += val[0] + '<br>';
                });
                showErrorToast(msg);
            } else {
                showErrorToast('{{ __('Something went wrong.') }}');
            }
        }
    </script>
@endsection
