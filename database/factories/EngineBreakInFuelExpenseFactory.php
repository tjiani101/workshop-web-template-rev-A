<?php

namespace Database\Factories;

use App\Models\EngineBreakInFuelExpense;
use App\Models\EngineBreakInProgram;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EngineBreakInFuelExpense>
 */
class EngineBreakInFuelExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'engine_break_in_program_id' => EngineBreakInProgram::factory(),
            'purchased_at' => now(),
            'quantity_liters' => 2.5,
            'expense_rupiah' => 100000,
            'customer_contribution_rupiah' => 80000,
            'minex_subsidy_rupiah' => 20000,
            'receipt_path' => 'minex/break-in-receipts/test-receipt.jpg',
            'recorded_by' => User::factory(),
        ];
    }
}
