@extends('errors.layout')

@section('title', __('Feature unavailable'))
@section('code', '400')
@section('icon', 'fa-shield')
@section('heading', __('Feature unavailable'))
@section('message')
    {{ __('Your current subscription does not include this website-management feature. Ask an administrator to review the School subscription before trying again.') }}
@endsection
