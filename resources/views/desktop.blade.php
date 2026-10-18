<!DOCTYPE html>
<html lang="pl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>RecruitFlow Desktop</title>
        <link rel="stylesheet" href="{{ asset('css/desktop.css') }}">
    </head>
    <body>
        <main class="welcome">
            <div class="app-mark" aria-hidden="true">RF</div>
            <p class="eyebrow">Twoje miejsce pracy</p>
            <h1>RecruitFlow Desktop</h1>
            @if (config('nativephp-internal.running'))
                <p class="status"><span class="status-dot" aria-hidden="true"></span>Aplikacja działa natywnie na macOS.</p>
                <p class="description">RecruitFlow jest gotowy do pracy w oknie desktopowym. Znajdziesz go także na pasku menu macOS.</p>
            @else
                <p class="status">Podgląd w przeglądarce</p>
                <p class="description">Uruchom RecruitFlow Desktop za pomocą <code>php artisan native:run</code>, aby otworzyć aplikację natywnie na macOS.</p>
            @endif
        </main>
    </body>
</html>
