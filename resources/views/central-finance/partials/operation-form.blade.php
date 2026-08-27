<select name="category_id" class="form-control mb-2" required><option value="">{{ __('Category') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
<select name="fund_account_id" class="form-control mb-2 central-fund-account-selector" required><option value="">{{ __('Fund Account') }}</option>@foreach($accounts as $account)@include('central-finance.partials.fund-account-option',['account'=>$account])@endforeach</select>
<input name="amount" type="number" step="0.01" min="0.01" class="form-control mb-2" placeholder="{{ __('Amount') }}" required>
<input name="payment_method" class="form-control mb-2" value="Cash" required>
<input name="reference_no" class="form-control mb-2" placeholder="{{ __('Reference') }}">
@if($payer)<input name="payer" class="form-control mb-2" placeholder="{{ __('Payer') }}">@endif
<textarea name="description" class="form-control mb-2" placeholder="{{ __('Description') }}"></textarea>
<button class="btn btn-theme">{{ __($actionLabel) }}</button>
