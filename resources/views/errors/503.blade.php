@extends('errors.layout')

@section('title', __('Service temporarily unavailable'))
@section('code', '503')
@section('icon', 'fa-wrench')
@section('heading', __('Service temporarily unavailable'))
@section('message')
    {{ __('eSchool is undergoing brief maintenance. Please try again in a few minutes.') }}
@endsection
