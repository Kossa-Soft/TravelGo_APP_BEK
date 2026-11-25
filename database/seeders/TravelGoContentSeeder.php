<?php

namespace Database\Seeders;

use App\Models\TravelGoContent;
use App\Support\TravelGoType;
use Illuminate\Database\Seeder;

class TravelGoContentSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            'UA' => ['uk' => 'Україна', 'en' => 'Ukraine'],
            'FR' => ['uk' => 'Франція', 'en' => 'France'],
            'IT' => ['uk' => 'Італія', 'en' => 'Italy'],
            'ES' => ['uk' => 'Іспанія', 'en' => 'Spain'],
            'US' => ['uk' => 'США', 'en' => 'United States'],
        ];

        foreach ($countries as $code => $name) {
            TravelGoContent::query()->firstOrCreate(['country_code' => $code], ['type' => TravelGoType::Country, 'name' => $name]);
        }
    }
}
