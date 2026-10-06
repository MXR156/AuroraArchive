<?php

return [
    'media_root' => env('MEDIA_ROOT', '/media'),
    'accelerated_streaming' => env('MEDIA_ACCELERATED_STREAMING', false),
    'config_root' => env('AURORAARCHIVE_CONFIG_ROOT', env('AuroraArchive_CONFIG_ROOT', '/config')),
    'yt_dlp' => env('YT_DLP_BINARY', 'yt-dlp'),
    'yt_dlp_plugin_dir' => env('YT_DLP_PLUGIN_DIR'),
    'yt_dlp_pot_provider_url' => env('YT_DLP_POT_PROVIDER_URL'),
    'deno' => env('DENO_BINARY', 'deno'),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'temp_root' => env('AURORAARCHIVE_TEMP_ROOT', storage_path('app/tmp')),
];
