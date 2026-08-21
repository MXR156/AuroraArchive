<x-layouts.app title="Settings">
    <div class="mx-auto grid max-w-3xl gap-6">
        <div>
            <p class="text-sm text-zinc-500">Settings</p>
            <h1 class="text-3xl font-bold">YouTube</h1>
        </div>

        <section class="rounded-2xl border border-white/10 bg-zinc-900 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="font-semibold">yt-dlp updates</h2>
                    <p class="mt-1 text-sm text-zinc-500">Installed version: {{ $ytDlpVersion ?: 'Unavailable' }}</p>
                </div>
                <form method="POST" action="{{ route('settings.yt-dlp.update') }}" class="flex items-center gap-2">
                    @csrf
                    <select name="channel" class="field min-w-32" aria-label="yt-dlp release channel">
                        <option value="nightly">Nightly</option>
                        <option value="stable">Stable</option>
                    </select>
                    <button class="secondary">Update now</button>
                </form>
            </div>
        </section>

        <section class="rounded-2xl border border-white/10 bg-zinc-900 p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="font-semibold">TubeSync import</h2>
                    <p class="mt-1 text-sm text-zinc-500">Preview monitored sources, metadata, and files found under the media root.</p>
                </div>
                <a href="{{ route('imports.tubesync') }}" class="secondary">Open importer</a>
            </div>
        </section>

        <section class="rounded-2xl border border-white/10 bg-zinc-900 p-6">
            <div class="mb-6 flex items-center justify-between gap-4">
                <div>
                    <h2 class="font-semibold">YouTube authentication</h2>
                    <p class="mt-1 text-sm text-zinc-500">{{ $credential?->status_message ?: 'No database-managed cookies configured.' }}</p>
                </div>
                <span class="badge">{{ str_replace('_', ' ', $credential?->status->value ?? 'not configured') }}</span>
            </div>

            <form method="POST" action="{{ route('settings.cookies.store') }}" enctype="multipart/form-data" class="grid gap-4 border-t border-white/8 pt-6">
                @csrf
                @method('PUT')
                <input type="file" name="cookies" required accept=".txt,text/plain" class="field">
                <button class="primary w-fit">Encrypt and save</button>
            </form>

            @if($credential)
                <div class="mt-6 flex gap-2 border-t border-white/8 pt-6">
                    <form method="POST" action="{{ route('settings.cookies.test') }}">
                        @csrf
                        <button class="secondary">Test authentication</button>
                    </form>
                    <form method="POST" action="{{ route('settings.cookies.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <button class="danger">Remove cookies</button>
                    </form>
                </div>
            @endif
        </section>

        <p class="text-sm text-zinc-500">Advanced fallback: place <code>cookies.txt</code> in the configured application data directory. Database cookies take precedence.</p>
    </div>
</x-layouts.app>
