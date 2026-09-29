<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $companyName }}</title>
</head>
<body dir="{{ \App\Support\Locales::direction() }}" style="margin:0;padding:0;background:#f3f6f6;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0e1a1c;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6f6;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" align="center" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">
                    <tr>
                        <td style="padding:0 4px 16px;font-size:18px;font-weight:700;">
                            <span style="display:inline-block;width:14px;height:14px;border-radius:4px;background:{{ $accent }};vertical-align:-1px;margin-inline-end:6px;"></span>{{ $companyName }}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff;border:1px solid #d9e2e2;border-radius:10px;padding:28px 28px 20px;font-size:15px;line-height:1.6;">
                            <div class="nb-body">{!! $body !!}</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 4px;font-size:12px;color:#8a9a9b;">
                            &copy; {{ date('Y') }} {{ $companyName }}
                            @if ($showPoweredBy)
                                &middot; {{ __('Sent with') }} <a href="{{ \App\Support\Branding::PRODUCT_URL }}" style="color:#8a9a9b;">{{ \App\Support\Branding::PRODUCT_NAME }}</a>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
