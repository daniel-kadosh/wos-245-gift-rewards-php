<?php

use Leaf\Schema;
use Leaf\Database;
use Illuminate\Database\Schema\Blueprint;

// CREATE TABLE players ( player_id varchar(255), player_name varchar(255), last_message varchar(255) );
// Divergent(36929232): ZwB2BnwX2: Gift code already used.

class CreatePlayers extends Database
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        if (!static::$capsule::schema()->hasTable("players")) {
            static::$capsule::schema()->create("players", function (Blueprint $table) {
                $table->primary('player_id');
         		$table->string('player_name');
         		$table->string('last_message');
         		$table->timestamps();
         	});
        }
    }

    /**
     * Reverse the migrations.
     * @return void
     */
    public function down()
    {
        static::$capsule::schema()->dropIfExists('users');
    }
}