{{-- Shown by the phone app's service worker when there is no connection. It cannot load files, so it is one page. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('You are offline') }}</title>
</head>
<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#0e2b47;color:#fff;font:16px system-ui,sans-serif;text-align:center;padding:24px;box-sizing:border-box">
    <div>
        <h1 style="font-size:1.3rem">{{ __('You are offline') }}</h1>
        <p style="opacity:.85">{{ __('The admin area opens again when your phone is back online.') }}</p>
        <button type="button" onclick="location.reload()" style="font:inherit;font-weight:700;min-height:44px;padding:0 18px;border-radius:8px;border:0;background:#0b7a70;color:#fff">{{ __('Try again') }}</button>
    </div>
</body>
</html>
