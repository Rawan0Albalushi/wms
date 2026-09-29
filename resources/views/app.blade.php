<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>WMS</title>
        <script>
            (function () {
                var locale = localStorage.getItem('wms-locale') || 'ar';
                document.documentElement.lang = locale;
                document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';
            })();
        </script>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @viteReactRefresh
            @vite(['resources/css/app.css', 'resources/js/main.jsx'])
        @endif
    </head>
    <body>
        <div id="root"></div>
    </body>
</html>
