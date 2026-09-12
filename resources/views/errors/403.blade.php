@extends('errors.layout')

@section('title', __('Access denied'))
@section('code', '403')
@section('icon', 'fa-lock')
@section('heading', __('Access denied'))
@section('message')
    {{ __('Your role does not allow access to this page. No data was changed.') }}
@endsection
