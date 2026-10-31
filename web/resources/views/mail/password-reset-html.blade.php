@extends('mail.layout')

@section('title', __('auth.reset.title'))
@section('preheader', __('auth.reset.mail_button'))

@section('content')
    <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#9aa69b;">{{ __('auth.reset.mail_body', ['min' => $minutes]) }}</p>
    {{-- the button (a link styled as one: works in every email app) --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
        <tr>
            <td bgcolor="#c8ff3d" style="border-radius:999px;background:#c8ff3d;background-image:linear-gradient(135deg,#d9ff5c 0%,#5ef0a8 100%);">
                <a href="{{ $url }}" style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:800;color:#0d1404;text-decoration:none;border-radius:999px;">{{ __('auth.reset.mail_button') }}</a>
            </td>
        </tr>
    </table>
    <p style="margin:0 0 6px;font-size:12px;line-height:1.5;color:#657066;">{{ __('auth.reset.mail_link_hint') }}</p>
    <p style="margin:0;font-size:12px;line-height:1.5;word-break:break-all;"><a href="{{ $url }}" style="color:#9aa69b;">{{ $url }}</a></p>
@endsection
