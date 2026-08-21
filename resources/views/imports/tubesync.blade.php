<x-layouts.app title="TubeSync import">
    <div class="grid gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-zinc-500">Settings</p>
                <h1 class="text-3xl font-bold">TubeSync import</h1>
            </div>
            @if($preview)
                <p class="text-sm text-zinc-400">Media root: <code>{{ $preview['media_root'] }}</code></p>
            @endif
        </div>

        @if($connectionError)
            <section class="border-l-2 border-red-500 bg-red-500/10 p-5 text-red-100">
                <h2 class="font-semibold">TubeSync database unavailable</h2>
                <p class="mt-2 break-words text-sm text-red-200">{{ $connectionError }}</p>
            </section>
        @else
            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-white/10 bg-white/10 sm:grid-cols-5">
                @foreach(['sources' => 'Sources', 'media' => 'Unique videos', 'files' => 'Files found', 'thumbnails' => 'Thumbnails', 'queue_candidates' => 'Missing eligible'] as $key => $label)
                    <div class="bg-zinc-900 p-4">
                        <p class="text-xs text-zinc-500">{{ $label }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ number_format($preview['totals'][$key]) }}</p>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('imports.tubesync.store') }}" class="grid gap-5">
                @csrf
                <div class="overflow-x-auto rounded-lg border border-white/10">
                    <table class="w-full min-w-[56rem] text-left text-sm">
                        <thead class="bg-zinc-900 text-xs text-zinc-400">
                            <tr>
                                <th class="w-12 p-4"><span class="sr-only">Select</span></th>
                                <th class="p-4 font-medium">Source</th>
                                <th class="p-4 font-medium">Type</th>
                                <th class="p-4 text-right font-medium">Videos</th>
                                <th class="p-4 text-right font-medium">Downloaded</th>
                                <th class="p-4 text-right font-medium">Files found</th>
                                <th class="p-4 text-right font-medium">Thumbnails</th>
                                <th class="p-4 text-right font-medium">Missing eligible</th>
                                <th class="p-4 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/8">
                            @forelse($preview['sources'] as $source)
                                <tr class="bg-zinc-950 hover:bg-white/[0.03]">
                                    <td class="p-4"><input type="checkbox" name="sources[]" value="{{ $source['uuid'] }}" checked class="size-4 accent-violet-500" aria-label="Import {{ $source['name'] }}"></td>
                                    <td class="p-4 font-medium">{{ $source['name'] }}</td>
                                    <td class="p-4 capitalize text-zinc-400">{{ $source['type'] }}</td>
                                    <td class="p-4 text-right tabular-nums">{{ number_format($source['media_count']) }}</td>
                                    <td class="p-4 text-right tabular-nums">{{ number_format($source['downloaded_count']) }}</td>
                                    <td class="p-4 text-right tabular-nums">{{ number_format($source['existing_files']) }}</td>
                                    <td class="p-4 text-right tabular-nums">{{ number_format($source['existing_thumbnails']) }}</td>
                                    <td class="p-4 text-right tabular-nums">{{ number_format($source['queue_candidates']) }}</td>
                                    <td class="p-4 text-zinc-400">{{ $source['already_imported'] ? 'Imported' : 'New' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="p-8 text-center text-zinc-500">No monitored TubeSync sources were found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($preview['sources'])
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-white/10 pt-5">
                        <label class="flex max-w-2xl items-start gap-3 text-sm">
                            <input type="checkbox" name="queue_missing" value="1" class="mt-0.5 size-4 accent-violet-500">
                            <span><strong class="block text-zinc-100">Queue missing eligible videos</strong><span class="text-zinc-500">Leave this off to import the catalogue, existing files, and thumbnails without starting downloads.</span></span>
                        </label>
                        <button class="primary">Import selected</button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</x-layouts.app>
