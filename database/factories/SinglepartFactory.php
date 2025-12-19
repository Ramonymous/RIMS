<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Singlepart>
 */
class SinglepartFactory extends Factory
{
    public function definition(): array
    {
        return [
            // contoh: 64312-I7000
            'part_number' => 
                $this->faker->numberBetween(10000, 99999)
                . '-'
                . strtoupper($this->faker->bothify('I####')),

            // contoh: PNL-DASH
            'part_name' => strtoupper(
                $this->faker->randomElement([
                    'PNL-DASH',
                    'PNL-FLOOR',
                    'BRKT-ENG',
                    'COVER-BODY',
                    'FRAME-SIDE'
                ])
            ),

            // contoh: HMMI
            'customer_code' => $this->faker->randomElement([
                'HMMI', 'ADM', 'TAM'
            ]),

            // contoh: ASI 1
            'supplier_code' => $this->faker->randomElement([
                'ASI 1', 'ASI 2', 'ASI 3'
            ]),

            // contoh: SU2id
            'model' => $this->faker->randomElement([
                'SU2id', 'SU2', 'SU3', 'DG1'
            ]),

            // contoh: LHD M/T
            'variant' => $this->faker->randomElement([
                'LHD M/T',
                'LHD A/T',
                'RHD M/T',
                'RHD A/T'
            ]),

            // contoh: 72
            'standard_packing' => $this->faker->randomElement([36, 48, 60, 72]),

            // contoh: 1000
            'stock' => $this->faker->numberBetween(100, 2000),

            // contoh: PALLET KH
            'address' => $this->faker->randomElement([
                'PALLET KH',
                'PALLET A1',
                'PALLET B2',
                'RACK C3'
            ]),

            // contoh: 1 (boolean database)
            'is_active' => 1,
        ];
    }
}
