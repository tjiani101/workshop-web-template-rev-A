<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\EngineBuildConfiguration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EngineBuildConfiguration>
 */
class EngineBuildConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'compatibility_status' => 'unverified',
        ];
    }
}
