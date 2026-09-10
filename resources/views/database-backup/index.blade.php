    @extends('layouts.master')

    @section('title')
        {{ __('database_backup') }}
    @endsection

    @section('content')
        <div class="content-wrapper">
            <div class="page-header">
                <h3 class="page-title">
                    {{ __('manage_backup') }}
                </h3>
            </div>
            <div class="row">
                <div class="col-md-12 grid-margin">
                    <div class="card" style="width: 100%; height: 300px;"> <!-- Fixed height and width -->
                        <div class="custom-card-body">
                            <div class="row">

                                <div class="col-md-12 text-left">
                                    <h4>{{ __('generate_backup') }}</h4>
                                    
                                    <button class="btn create-backup btn-theme btn-sm">{{ __('generate_backup') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    @endsection
