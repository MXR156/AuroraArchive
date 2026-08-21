<?php

namespace App\Contracts;

use App\Models\Media;
use App\Models\Source;

interface YoutubeDownloader
{
    /** @return list<array<string, mixed>> */
    public function discover(Source $source): array;

    /** @return array{exit_code:int,stdout:string,stderr:string,files:list<string>,version:?string} */
    public function download(Media $media): array;

    /** @return array{status:string,message:string} */
    public function testAuthentication(string $cookies): array;

    /** @return array{successful:bool,message:string,version:?string} */
    public function update(string $channel): array;

    public function version(): ?string;
}
