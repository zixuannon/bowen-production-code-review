<style>
    .modal-body .table td, 
    .modal-body .table th {
        padding: 0.75rem 1rem;
    }
    .btn-link:hover {
        text-decoration: none;
    }
    .small.text-muted {
        font-size: 80%;
    }
</style>
<div class="card">
    <div class="card-body">
        <h4 class="card-title">{{ __('fees_report') }}</h4>
        @if(isset($studentFees) && count($studentFees) > 0)
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ __('fees_name') }}</th>
                        <th>{{ __('type') }}</th>
                        <th>{{ __('amount') }}</th>
                        <th>{{ __('due_date') }}</th>
                        <th>{{ __('paid_amount') }}</th>
                        <th>{{ __('payment_mode') }}</th>
                        <th>{{ __('optional_fee_paid_amount') }}</th>
                        <th>{{ __('date') }}</th>
                        <th>{{ __('status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($studentFees as $fee)
                    <tr>
                        <td>{{ $fee->fees->name ?? '-' }}</td>
                        <td>
                            @if(isset($fee->fees->fees_class_type) && count($fee->fees->fees_class_type) > 0)
                                @if(isset($fee->fees->fees_class_type[0]->fees_type))
                                    {{ $fee->fees->fees_class_type[0]->fees_type->name ?? __('Compulsory') }}
                                @else
                                    {{ __('compulsory') }}
                                @endif
                            @else
                                {{ __('compulsory') }}
                            @endif
                        </td>
                        <td>{{ format_money($fee->amount ?? 0) }}</td>
                        <td>{{ $fee->fees->due_date ?: __('no_due_date') }}</td>
                        <td>
                            @php
                                $paidAmount = 0;
                                if(isset($fee->compulsory_fee) && count($fee->compulsory_fee) > 0) {
                                    foreach($fee->compulsory_fee as $cf) {
                                        $paidAmount += $cf->amount ?? 0;
                                    }
                                }
                            @endphp
                            {{ format_money($paidAmount) }}
                        </td>
                        <td>
                            @if(isset($fee->compulsory_fee) && count($fee->compulsory_fee) > 0)
                                {{ $fee->compulsory_fee[0]->mode ?? '-' }}
                            @else
                                -
                            @endif
                        </td>
                        <td>
                            @if(isset($fee->optional_fee) && count($fee->optional_fee) > 0)
                                @php
                                    $totalOptionalAmount = 0;
                                    foreach($fee->optional_fee as $of) {
                                        $totalOptionalAmount += $of->amount ?? 0;
                                    }
                                @endphp
                                <div class="d-flex align-items-center">
                                    <span>{{ format_money($totalOptionalAmount) }}</span>
                                    @if(count($fee->optional_fee) > 1)
                                        <button type="button" class="btn btn-link btn-sm ml-2 p-0" 
                                                data-toggle="modal" 
                                                data-target="#optionalFeesModal-{{ $fee->id }}">
                                            <i class="fa fa-list-ul text-primary"></i>
                                        </button>
                                    @endif
                                </div>
                                
                                <!-- Optional Fees Modal -->
                                @if(count($fee->optional_fee) > 1)
                                <div class="modal fade" id="optionalFeesModal-{{ $fee->id }}" tabindex="-1" role="dialog">
                                    <div class="modal-dialog modal-dialog-centered" role="document">
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h5 class="modal-title">{{ __('Optional Fees Details') }}</h5>
                                                <button type="button" class="close" data-dismiss="modal">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <div class="modal-body p-0">
                                                <div class="table-responsive">
                                                    <table class="table mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>#</th>
                                                                <th>{{ __('Amount') }}</th>
                                                                <th>{{ __('payment_mode') }}</th>
                                                                <th>{{ __('cheque_no') }}</th>
                                                                <th>{{ __('Date') }}</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($fee->optional_fee as $index => $of)
                                                            <tr>
                                                                <td>{{ $index + 1 }}</td>
                                                                <td>{{ format_money($of->amount ?? 0) }}</td>
                                                                <td>{{ $of->mode ?? '-' }}</td>
                                                                <td>{{ $of->cheque_no ?? '-' }}</td>
                                                                <td>{{ $of->created_at ? date('d/m/Y', strtotime($of->created_at)) : '-' }}</td>
                                                            </tr>
                                                            @endforeach
                                                        </tbody>
                                                        <tfoot>
                                                            <tr class="table-primary">
                                                                <th>{{ __('Total') }}</th>
                                                                <th colspan="3">{{ format_money($totalOptionalAmount) }}</th>
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $fee->date ?? '-' }}</td>
                        <td>
                            @php
                                $status = $fee->status ?? 'unpaid';
                                
                                $badgeClass = 'badge-secondary';
                                if($status == 'paid') {
                                    $badgeClass = 'badge-success';
                                } elseif($status == 'partial') {
                                    $badgeClass = 'badge-warning';
                                } elseif($status == 'unpaid') {
                                    $badgeClass = 'badge-secondary';
                                } elseif($status == 'overdue') {
                                    $badgeClass = 'badge-danger';
                                }
                            @endphp
                            <label class="badge {{ $badgeClass }}">{{ ucfirst($status) }}</label>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="alert alert-info mt-3">
            {{ __('no_fees_records_found_for_this_student') }}
        </div>
        @endif
        

        
    </div>
</div>
