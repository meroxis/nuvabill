{{-- Signs the client in to a control panel that only accepts a form (for example CyberPanel). The form is sent from the client's own browser. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Support\Locales::direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Opening your control panel') }} · {{ setting('company.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif; background: #f6f7f9; color: #1d2433; }
        main { text-align: center; padding: 24px; }
        button { margin-top: 12px; padding: 10px 18px; border: 0; border-radius: 6px; background: #1d2433; color: #fff; font: inherit; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <p>{{ __('Opening your control panel…') }}</p>
        <form id="panel-login" method="POST" action="{{ $form['url'] }}">
            @foreach ($form['fields'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <button type="submit">{{ __('Continue') }}</button>
        </form>
    </main>
    <script>document.getElementById('panel-login').submit();</script>
</body>
</html>
