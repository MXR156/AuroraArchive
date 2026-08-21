<x-layouts.app :title="'Edit '.$medium->title">
    <div class="mx-auto grid max-w-3xl gap-6">
        <header>
            <a href="{{ route('media.show', $medium) }}" class="text-sm text-zinc-500 hover:text-zinc-300">Back to video</a>
            <h1 class="mt-1 text-3xl font-bold">Edit metadata</h1>
        </header>

        <form method="POST" action="{{ route('media.update', $medium) }}" class="grid gap-5">
            @csrf
            @method('PUT')
            <label class="grid gap-2 text-sm font-medium">
                Channel name
                <input name="channel_name" value="{{ old('channel_name', $medium->channel_name) }}" maxlength="255" class="field">
            </label>
            <label class="grid gap-2 text-sm font-medium">
                Title
                <input name="title" value="{{ old('title', $medium->title) }}" required maxlength="255" class="field">
            </label>
            <label class="grid gap-2 text-sm font-medium">
                Description
                <textarea name="description" rows="16" maxlength="100000" class="field resize-y">{{ old('description', $medium->description) }}</textarea>
            </label>
            <div class="flex items-center justify-end gap-2 border-t border-white/10 pt-5">
                <a href="{{ route('media.show', $medium) }}" class="secondary">Cancel</a>
                <button class="primary">Save changes</button>
            </div>
        </form>
    </div>
</x-layouts.app>
