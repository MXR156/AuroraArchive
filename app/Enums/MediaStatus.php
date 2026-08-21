<?php

namespace App\Enums;

enum MediaStatus: string
{
    case Discovered = 'discovered';
    case Queued = 'queued';
    case Downloading = 'downloading';
    case Downloaded = 'downloaded';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
