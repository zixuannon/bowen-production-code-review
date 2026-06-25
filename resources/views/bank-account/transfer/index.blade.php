@extends('layouts.master')

@section('title')
    {{ __('Bank Transfer') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">
                {{ __('Bank Transfer') }}
            </h3>
        </div>

        {{-- Create Form --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('New Transfer') }}</h4>
                        <form class="pt-3" id="create-form" novalidate="novalidate">
                            @csrf
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('From Account') }} <span class="text-danger">*</span></label>
                                    <select name="from_account_id" id="from_account_id" class="form-control" required>
                                        <option value="">{{ __('Select Account') }}</option>
                                        @foreach ($bankAccounts as $account)
                                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}">
                                                {{ $account->account_name }} ({{ $account->currency }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-3">
                                    <label>{{ __('To Account') }} <span class="text-danger">*</span></label>
                                    <select name="to_account_id" id="to_account_id" class="form-control" required>
                                        <option value="">{{ __('Select Account') }}</option>
                                        @foreach ($bankAccounts as $account)
                                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}">
                                                {{ $account->account_name }} ({{ $account->currency }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Amount') }} <span class="text-danger">*</span></label>
                                    <input name="amount" id="amount" type="number" min="0.01" step="0.01"
                                        class="form-control" placeholder="0.00" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Transfer Date') }} <span class="text-danger">*</span></label>
                                    <input name="transfer_date" id="transfer_date" type="text"
                                        class="datepicker-popup-no-future form-control" autocomplete="off" required />
                                </div>

                                <div class="form-group col-sm-12 col-md-2">
                                    <label>{{ __('Reference No.') }}</label>
                                    <input name="reference_no" id="reference_no" type="text" class="form-control"
                                        placeholder="{{ __('Optional') }}" maxlength="100" />
                                </div>

                                <div class="form-group col-sm-12 col-md-4">
                                    <label>{{ __('Notes') }}</label>
                                    <input name="notes" id="notes" type="text" class="form-control"
                                        placeholder="{{ __('Optional notes') }}" maxlength="1000" />
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

        {{-- Transfer List --}}
        <div class="row">
            <div class="col-md-12 grid-margin stretch-card">
                <div class="card">
                    <div class="card-body">
                        <h4 class="card-title">{{ __('Transfer History') }}</h4>

                        <table aria-describedby="mydesc" class='table' id='table_list' data-toggle="table"
                            data-url="{{ route('bank-transfers.list') }}" data-click-to-select="true"
                            data-side-pagination="server" data-pagination="true" data-page-list="[5, 10, 20, 50, 100]"
                            data-search="true" data-show-columns="true" data-show-refresh="true"
                            data-trim-on-search="false" data-mobile-responsive="true"
                            data-sort-name="transfer_date" data-sort-order="desc"
                            data-escape="true">
                            <thead>
                                <tr>
                                    <th scope="col" data-field="id" data-sortable="true" data-visible="false">{{ __('ID') }}</th>
                                    <th scope="col" data-field="no">{{ __('No.') }}</th>
                                    <th scope="col" data-field="transfer_date" data-sortable="true">{{ __('Transfer Date') }}</th>
                                    <th scope="col" data-field="from_account_name">{{ __('From Account') }}</th>
                                    <th scope="col" data-field="to_account_name">{{ __('To Account') }}</th>
                                    <th scope="col" data-field="amount" data-sortable="true"
                                        data-formatter="amountFormatter">{{ __('Amount') }}</th>
                                    <th scope="col" data-field="reference_no">{{ __('Reference No.') }}</th>
                                    <th scope="col" data-field="notes">{{ __('Notes') }}</th>
                                    <th scope="col" data-field="status_badge" data-escape="false">{{ __('Status') }}</th>
                                    <th scope="col" data-field="operate" data-events="transferEvents" data-escape="false">
                                        {{ __('Action') }}
                                    </th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
@endsection

@section('js')
    <script>
        // Amount formatter
        function amountFormatter(value, row) {
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

            $.ajax({
                type: 'POST',
                url: '{{ route('bank-transfers.store') }}',
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
                    if (xhr.status === 422) {
                        var resp = xhr.responseJSON;
                        if (resp.message) {
                            showErrorToast(resp.message);
                        } else if (resp.errors) {
                            var msg = '';
                            $.each(resp.errors, function(key, val) {
                                msg += val[0] + '<br>';
                            });
                            showErrorToast(msg);
                        }
                    } else {
                        showErrorToast('{{ __('Something went wrong.') }}');
                    }
                }
            });
        });

        // ========== Cancel Transfer ==========
        window.transferEvents = {
            'click .delete-btn': function(e, value, row) {
                if (row.status !== 'completed') return;

                Swal.fire({
                    title: '{{ __('Are you sure?') }}',
                    text: '{{ __('This transfer will be cancelled and balances will be updated.') }}',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: '{{ __('Yes, cancel it!') }}',
                    cancelButtonText: '{{ __('Cancel') }}'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            type: 'DELETE',
                            url: '{{ url('bank-transfers') }}/' + row.id,
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
                                var resp = xhr.responseJSON;
                                if (resp && resp.message) {
                                    showErrorToast(resp.message);
                                } else {
                                    showErrorToast('{{ __('Something went wrong.') }}');
                                }
                            }
                        });
                    }
                });
            }
        };
    </script>
@endsection
