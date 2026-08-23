<?php

namespace Database\Factories;

use App\Models\MediaTombstone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaTombstone>
 */
class MediaTombstoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'youtube_id' => fake()->unique()->regexify('[A-Za-z0-9_-]{11}'),
            'reason' => 'deleted_by_user',
        ];
    }
}
