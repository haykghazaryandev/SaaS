@extends('mail.layout')

@section('title', __('auth.email.mail_title'))
@section('preheader', __('auth.email.mail_hint', ['min' => $minutes]))

@section('content')
    @include('mail.partials.code', ['code' => $code])
    <p style="margin:0;font-size:14px;line-height:1.6;color:#9aa69b;">{{ __('auth.email.mail_hint', ['min' => $minutes]) }}</p>
@endsection
