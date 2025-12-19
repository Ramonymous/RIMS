<?php

namespace Database\Seeders;

use App\Models\Singlepart;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SinglepartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Singlepart::factory(200)->create();
    }
}
