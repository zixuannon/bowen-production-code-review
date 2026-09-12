@extends('errors.layout')

@section('title', __('Page not found'))
@section('code', '404')
@section('icon', 'fa-map-marker')
@section('heading', __('Page not found'))
@section('message')
    {{ __('The page may have moved, or you may not have access to this location. Return to a safe starting point and try again.') }}
@endsection
