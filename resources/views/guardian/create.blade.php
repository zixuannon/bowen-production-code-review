@extends('layouts.master')

@section('title')
    {{ __('create') . ' ' . __('Guardian') }}
@endsection

@section('content')
    <div class="content-wrapper">
        <div class="page-header">
            <h3 class="page-title">{{ __('create') . ' ' . __('Guardian') }}</h3>
        </div>

        <div class="row grid-margin">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-end mb-3">
                            <a class="btn btn-sm btn-theme" href="{{ route('guardian.index') }}">{{ __('back') }}</a>
                        </div>

                        <form class="create-form" action="{{ route('guardian.store') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="row">
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="guardian_first_name">{{ __('first_name') }} <span class="text-danger">*</span></label>
                                    <input id="guardian_first_name" name="first_name" type="text" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="guardian_last_name">{{ __('last_name') }} <span class="text-danger">*</span></label>
                                    <input id="guardian_last_name" name="last_name" type="text" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="guardian_email">{{ __('email') }} <span class="text-danger">*</span></label>
                                    <input id="guardian_email" name="email" type="email" class="form-control" required>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="guardian_mobile">{{ __('mobile') }} <span class="text-danger">*</span></label>
                                    <input id="guardian_mobile" name="mobile" type="tel" class="form-control" inputmode="numeric" required>
                                </div>
                                <div class="form-group col-sm-12">
                                    <label>{{ __('gender') }} <span class="text-danger">*</span></label>
                                    <div class="d-flex">
                                        <div class="form-check form-check-inline">
                                            <label class="form-check-label" for="guardian_male">
                                                <input class="form-check-input" id="guardian_male" type="radio" name="gender" value="male" required>
                                                {{ __('male') }}
                                            </label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <label class="form-check-label" for="guardian_female">
                                                <input class="form-check-input" id="guardian_female" type="radio" name="gender" value="female" required>
                                                {{ __('female') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group col-sm-12 col-md-6">
                                    <label for="guardian_image">{{ __('image') }}</label>
                                    <input id="guardian_image" name="image" type="file" accept="image/*" class="form-control">
                                </div>
                                <div class="col-sm-12">
                                    <button type="submit" class="btn btn-theme">{{ __('submit') }}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
