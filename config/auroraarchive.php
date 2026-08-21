<?php

return [
    'media_root' => env('MEDIA_ROOT', '/media'),
    'config_root' => env('AURORAARCHIVE_CONFIG_ROOT', env('AuroraArchive_CONFIG_ROOT', '/config')),
    'yt_dlp' => env('YT_DLP_BINARY', 'yt-dlp'),
    'deno' => env('DENO_BINARY', 'deno'),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
];
