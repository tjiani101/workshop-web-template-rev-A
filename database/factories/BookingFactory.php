<?php

namespace Database\Factories;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_number' => 'MX-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
            'reference' => Str::random(40),
            'submission_token' => Str::uuid()->toString(),
            'customer_type' => 'guest',
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => '+62 812 0000 0000',
            'vehicle_snapshot' => ['brand' => 'Honda', 'model' => 'Test motorcycle'],
            'status' => 'pending_approval',
            'payment_status' => 'not_due',
            'payment_percentage' => 100,
            'submitted_at' => now(),
        ];
    }
}
