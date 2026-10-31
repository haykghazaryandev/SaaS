{{-- The HTML emails (codes, password reset): ZoneSpot's dark + lime look. Email apps support little CSS: tables and
     inline styles only, no web fonts. The logo is a hosted PNG (public/images/email, 2× for sharp screens): SVG and
     inline (base64) images are blocked by Gmail / Outlook. When images are off, a lime square with "ZS" shows instead.
     Each email also has a plain-text twin. --}}
@php
    // a public https site: the hosted image (best: small email, cached). Otherwise (a developer's localhost, which
    // email apps cannot reach): the image travels inside the email (CID). $message exists only while sending.
    $appUrl = rtrim((string) config('app.url'), '/');
    $host = (string) parse_url($appUrl, PHP_URL_HOST);
    $public = str_starts_with($appUrl, 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true) && ! str_ends_with($host, '.test');
    $logo = ! $public && isset($message)
        ? $message->embed(public_path('images/email/logo-mark@2x.png'))
        : $appUrl.'/images/email/logo-mark@2x.png';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- iPhone Mail: keep our layout, and do not turn the code into a phone link --}}
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    {{-- dark by design: asks email apps not to invert the colours --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>@yield('title')</title>
    <!--[if mso]><style>table,td,h1,p,a{font-family:Arial,sans-serif !important;}</style><![endif]-->
</head>
<body style="margin:0;padding:0;width:100%;background:#0a0d0b;color:#eef5ec;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Armenian',Arial,sans-serif;-webkit-text-size-adjust:100%;">
    {{-- the line email apps show next to the subject; the spacer keeps the body text out of it --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">@yield('preheader'){!! str_repeat('&#847;&zwnj;&nbsp;', 60) !!}</div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0a0d0b" style="background:#0a0d0b;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                {{-- desktop Outlook ignores max-width: a fixed-width table for it --}}
                <!--[if mso]><table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                    {{-- the logo + name (the name is text: readable with images off and by screen readers) --}}
                    <tr>
                        <td style="padding:0 4px 20px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="44" height="44" align="center" valign="middle" bgcolor="#c8ff3d" style="width:44px;height:44px;border-radius:11px;background:#c8ff3d;">
                                        <a href="{{ config('app.url') }}" style="text-decoration:none;">
                                            <img src="{{ $logo }}" width="44" height="44" alt="ZS"
                                                style="display:block;width:44px;height:44px;border:0;outline:none;text-decoration:none;border-radius:11px;color:#0d1404;font-size:15px;font-weight:800;line-height:44px;text-align:center;">
                                        </a>
                                    </td>
                                    <td style="padding-left:12px;font-size:20px;font-weight:700;color:#eef5ec;letter-spacing:-0.3px;">ZoneSpot</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    {{-- the card --}}
                    <tr>
                        <td bgcolor="#141915" style="background:#141915;border:1px solid #2a332b;border-radius:20px;padding:32px 28px;">
                            <h1 style="margin:0 0 12px;font-size:24px;line-height:1.25;font-weight:800;color:#eef5ec;">@yield('title')</h1>
                            @yield('content')
                        </td>
                    </tr>
                    {{-- footer --}}
                    <tr>
                        <td style="padding:20px 8px 0;font-size:12px;line-height:1.6;color:#657066;">
                            {{ __('auth.email.ignore') }}<br>
                            <a href="{{ config('app.url') }}" style="color:#9aa69b;text-decoration:none;">ZoneSpot</a> · {{ __('common.city') }}
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
