<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\EngineBreakInProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EngineBreakInProgram>
 */
class EngineBreakInProgramFactory extends Factory
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
            'status' => 'booked',
            'customer_consent_at' => now(),
            'customer_consent_version' => 'minex-break-in-consent-v1',
        ];
    }
}
