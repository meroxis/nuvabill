{{-- Shared <head> tags. $assets is the list of Vite entry points for the page. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<script>
    try {
        const theme = localStorage.getItem('nb.theme');
        if (theme === 'light' || theme === 'dark') {
            document.documentElement.dataset.theme = theme;
        }
    } catch (e) {}
</script>
@fonts
@vite($assets)
