<?php
use Leaf\Schema;
use Leaf\Database;
use Illuminate\Database\Schema\Blueprint;

// CREATE TABLE players ( player_id varchar(255), player_name varchar(255), last_message varchar(255) );
// Divergent(36929232): ZwB2BnwX2: Gift code already used.

// Need to auto-run this on startup within the container:
// php leaf db:migrate -vvv
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
                $table->integer('id')->primary();
         		$table->string('player_name');
                $table->string('last_message');
                $table->string('avatar_image');
                $table->integer('stove_lv');
                $table->string('stove_lv_content');
                $table->timestamps(); // created_at, updated_at
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
