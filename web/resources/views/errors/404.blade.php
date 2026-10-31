@extends('errors.layout')

@section('code', '404')
@section('title', __('common.not_found_title'))
@section('text', $text ?? __('common.not_found'))
@section('actions')
    <a class="btn main" href="/">{{ __('nav.home') }}</a>
    <button type="button" class="btn ghost" onclick="history.length > 1 ? history.back() : (location.href = '/')">{{ __('common.back') }}</button>
@endsection
