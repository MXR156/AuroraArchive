<!DOCTYPE html>
<html lang="en" class="h-full bg-zinc-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/auroraarchive-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/auroraarchive-16.png') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('images/auroraarchive-192.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    @vite('resources/css/app.css')
    <title>Sign in &middot; AuroraArchive</title>
</head>
<body class="grid min-h-full place-items-center p-5 text-zinc-100">
    <main class="w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900 p-7">
        <div class="mb-8 flex items-center gap-3">
            <img src="{{ asset('images/auroraarchive-192.png') }}" alt="" class="size-12 object-contain">
            <div>
                <h1 class="text-xl font-semibold">AuroraArchive</h1>
                <p class="text-sm text-zinc-500">Your private video library</p>
            </div>
        </div>
        @if($errors->any())
            <p class="mb-5 rounded-lg bg-red-500/10 p-3 text-sm text-red-300">{{ $errors->first() }}</p>
        @endif
        <form method="POST" action="{{ route('login.store') }}" class="grid gap-4">
            @csrf
            <label class="grid gap-2 text-sm">Email<input type="email" name="email" value="{{ old('email') }}" required class="field"></label>
            <label class="grid gap-2 text-sm">Password<input type="password" name="password" required class="field"></label>
            <button class="primary">Sign in</button>
        </form>
        @if($canRegister)
            <div class="my-6 text-center text-xs text-zinc-600">FIRST RUN &middot; CREATE ADMINISTRATOR</div>
            <form method="POST" action="{{ route('register') }}" class="grid gap-3">
                @csrf
                <input name="name" placeholder="Name" required class="field">
                <input type="email" name="email" placeholder="Email" required class="field">
                <input type="password" name="password" placeholder="Password" required class="field">
                <input type="password" name="password_confirmation" placeholder="Confirm password" required class="field">
                <button class="secondary">Create administrator</button>
            </form>
        @endif
    </main>
</body>
</html>
