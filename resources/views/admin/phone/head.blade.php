{{-- Lets staff install the admin area as an app on their phone (the web app manifest and icons). --}}
<link rel="manifest" href="{{ route('admin.manifest') }}">
<meta name="theme-color" content="#0e2b47">
<meta name="apple-mobile-web-app-title" content="{{ setting('company.name') ?: 'Nuvabill' }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('images/app/apple-touch-icon.png') }}">
