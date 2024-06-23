<?php

namespace App\Database\Factories;

use App\Models\Player;

// CREATE TABLE players ( player_id varchar(255), player_name varchar(255), last_message varchar(255) );

class PlayerFactory extends Factory
{
    // If this model property isn't defined, Leaf will
    // try to generate the model name from the factory name
    public $model = Player::class;

    // You define your factory blueprint here
    // It should return an associative array
    public function definition(): array
    {
        return [
            'player_id' => $this->faker->name,
            'player_name' => strtolower($this->faker->userName),
            'last_message' => $this->faker->words(3,true),
            // 'email_verified_at' => tick()->now(),
            // 'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
            // 'remember_token' => $this->str::random(10),
        ];
    }
}
