<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' - ' : '' }}AuroraArchive</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-zinc-950 text-zinc-100 antialiased">
    <div class="min-h-screen lg:grid lg:grid-cols-[15rem_1fr]">
        <aside class="border-b border-white/8 bg-zinc-950 lg:fixed lg:inset-y-0 lg:w-60 lg:border-r lg:border-b-0">
            <div class="flex h-16 items-center justify-between px-5">
                <a href="{{ route('home') }}" class="flex items-center gap-3 font-semibold"><span class="grid size-9 place-items-center rounded-lg bg-violet-600">AA</span>AuroraArchive</a>
                <button class="nav-toggle secondary px-2.5 lg:hidden" type="button" aria-label="Toggle navigation">Menu</button>
            </div>
            <nav class="nav-menu hidden gap-1 px-3 pb-4 lg:flex lg:flex-col">
                @foreach([
                    ['home', 'Home'],
                    ['sources.index', 'Subscriptions'],
                    ['library', 'Library'],
                    ['channels.index', 'Channels'],
                    ['downloads', 'Downloads'],
                    ['settings', 'Settings'],
                    ['system-health', 'System Health'],
                ] as [$routeName, $label])
                    <a href="{{ route($routeName) }}" @class([
                        'rounded-lg px-3 py-2.5 text-sm font-medium',
                        'bg-white/8 text-white' => request()->routeIs($routeName),
                        'text-zinc-400 hover:bg-white/5 hover:text-white' => ! request()->routeIs($routeName),
                    ])>{{ $label }}</a>
                @endforeach
                <form method="POST" action="{{ route('logout') }}" class="mt-4 border-t border-white/8 pt-4">
                    @csrf
                    <button class="w-full rounded-lg px-3 py-2.5 text-left text-sm text-zinc-400 hover:bg-white/5">Sign out</button>
                </form>
            </nav>
        </aside>
        <main class="min-w-0 lg:col-start-2">
            <header class="sticky top-0 z-20 flex h-16 items-center border-b border-white/8 bg-zinc-950/95 px-4 backdrop-blur sm:px-7">
                <form action="{{ route('library') }}" class="mx-auto w-full max-w-xl">
                    <input name="q" value="{{ request('q') }}" placeholder="Search your archive" class="w-full rounded-full border border-white/10 bg-zinc-900 px-5 py-2.5 text-sm outline-none focus:border-zinc-600">
                </form>
            </header>
            @if($youtubeCredential && in_array($youtubeCredential->status->value, ['rejected', 'possibly_expired']))
                <div class="border-b border-amber-500/20 bg-amber-500/10 px-5 py-3 text-sm text-amber-100">
                    <strong>YouTube authentication requires attention.</strong> {{ $youtubeCredential->status_message }}
                    <a href="{{ route('settings') }}" class="ml-2 underline">Replace or test cookies</a>
                </div>
            @endif
            <div class="mx-auto max-w-[100rem] p-4 sm:p-7">
                @if(session('success'))
                    <div class="mb-6 rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>
                @endif
                @if($errors->any())
                    <div class="mb-6 rounded-lg border border-red-500/20 bg-red-500/10 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</div>
                @endif
                {{ $slot }}
            </div>
        </main>
    </div>
</body>
</html>
