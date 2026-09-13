@extends('layouts.master')

@section('title', __('add_bulk_data'))

@section('content')
<div class="content-wrapper">
    <div class="page-header"><h3 class="page-title">{{ __('add_bulk_data') }}</h3></div>

    @if($errors->any())
        <div class="alert alert-danger" role="alert"><strong>{{ __('Import could not be started') }}</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card"><div class="card-body">
        @if($studentImportV2Enabled ?? false)
            <div class="ui-section-switcher" aria-label="{{ __('Student import options') }}">
                <span class="small text-muted mr-auto px-2">{{ __('Choose the import path that matches your template.') }}</span>
                <a class="btn btn-outline-primary" href="{{ route('students.import-v2') }}">{{ __('Open Student Import V2') }}</a>
            </div>
        @endif

        <div class="row align-items-stretch">
            <div class="col-lg-4 mb-3 mb-lg-0">
                <section class="ui-form-section h-100 mb-0">
                    <div class="ui-form-section__header"><h5>{{ __('Legacy CSV template') }}</h5><p>{{ __('Use this path only for Schools that still use the legacy Student Import format.') }}</p></div>
                    <a class="btn btn-outline-primary btn-block" href="{{ route('student.bulk-data-sample') }}" download>{{ __('Download Import Template') }}</a>
                    <ul class="small text-muted pl-3 mt-3 mb-0">
                        <li>{{ __('Download the template before preparing the file.') }}</li>
                        <li>{{ __('Save the completed workbook as CSV before upload.') }}</li>
                        <li>{{ __('Student Code is treated as text and leading zeroes are preserved.') }}</li>
                    </ul>
                </section>
            </div>
            <div class="col-lg-8">
                <section class="ui-form-section mb-0">
                    <div class="ui-form-section__header"><h5>{{ __('Upload legacy Student file') }}</h5><p>{{ __('The server validates School identity, Student Code, class, and session before creating records.') }}</p></div>
                    <form id="create-form" enctype="multipart/form-data" action="{{ route('students.store-bulk-data') }}" method="POST">
                        @csrf
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label for="session_year_id">{{ __('Session Year') }} <span class="text-danger">*</span></label>
                                <select name="session_year_id" id="session_year_id" class="form-control select2">
                                    @foreach ($sessionYears as $sessionYear)<option value="{{ $sessionYear->id }}" {{ $sessionYear->default == 1 ? 'selected' : '' }}>{{ $sessionYear->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label for="class_section">{{ __('Class Section') }} <span class="text-danger">*</span></label>
                                <select name="class_section_id" id="class_section" class="form-control select2">
                                    <option value="">{{ __('select') . ' ' . __('Class') . ' ' . __('section') }}</option>
                                    @foreach ($class_section as $section)<option value="{{ $section->id }}">{{ $section->full_name }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        <div class="ui-upload-zone mb-3">
                            <label class="ui-upload-zone__title" for="legacy-student-import-file">{{ __('Student CSV file') }} <span class="text-danger">*</span></label>
                            <span class="ui-upload-zone__help">{{ __('Accepted file: CSV generated from the current legacy template.') }}</span>
                            <input id="legacy-student-import-file" type="file" name="file" accept=".csv,text/csv" class="form-control-file mt-3" required data-ui-file-input>
                            <span class="ui-upload-zone__file" data-ui-file-name data-empty-label="{{ __('No file selected') }}">{{ __('No file selected') }}</span>
                        </div>
                        <div class="form-check mb-3">
                            <label class="form-check-label user-select-none"><input type="checkbox" class="form-check-input" name="is_send_notification" id="send_notification"> {{ __('Send Notification') }}</label>
                        </div>
                        <div class="ui-sticky-actions">
                            <button class="btn btn-theme submit_bulk_file" type="submit" name="submit" id="submit_bulk_file">{{ __('Import Students') }}</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div></div>
</div>
@endsection

@section('js')
@endsection
