@extends('mail.layout')

@section('title', __('auth.verify.mail_title'))
@section('preheader', __('auth.verify.mail_hint', ['min' => $minutes]))

@section('content')
    <p style="margin:0 0 4px;font-size:15px;line-height:1.6;color:#9aa69b;">{{ __('auth.verify.mail_intro') }}</p>
    @include('mail.partials.code', ['code' => $code])
    <p style="margin:0;font-size:14px;line-height:1.6;color:#9aa69b;">{{ __('auth.verify.mail_hint', ['min' => $minutes]) }}</p>
@endsection
