<?php

return [
    'media_root' => env('MEDIA_ROOT', '/media'),
    'accelerated_streaming' => env('MEDIA_ACCELERATED_STREAMING', false),
    'config_root' => env('AURORAARCHIVE_CONFIG_ROOT', env('AuroraArchive_CONFIG_ROOT', '/config')),
    'yt_dlp' => env('YT_DLP_BINARY', 'yt-dlp'),
    'deno' => env('DENO_BINARY', 'deno'),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'temp_root' => env('AURORAARCHIVE_TEMP_ROOT', storage_path('app/tmp')),
];
