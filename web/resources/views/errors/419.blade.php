@extends('errors.layout')

@section('code', '419')
@section('title', __('common.page_expired_title'))
@section('text', $text ?? __('common.page_expired'))
@section('actions')
    <button type="button" class="btn main" onclick="location.reload()">{{ __('common.try_again') }}</button>
    <a class="btn ghost" href="/">{{ __('nav.home') }}</a>
@endsection
