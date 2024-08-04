<?php
use Leaf\Schema;
use Leaf\Database;
use Illuminate\Database\Schema\Blueprint;

/*
 * Sqlite schema after this:
 *
CREATE TABLE IF NOT EXISTS "players" (
    "id" integer not null,
    "player_name" varchar not null,
    "last_message" varchar not null,
    "avatar_image" varchar not null,
    "stove_lv" integer not null,
    "stove_lv_content" varchar not null,
    "created_at" datetime,
    "updated_at" datetime,
    "extra" text,
    primary key ("id"));
CREATE TABLE IF NOT EXISTS "alliances" (
    "id" integer not null primary key autoincrement,
    "short_name" varchar not null,
    "extra" text);
CREATE TABLE sqlite_sequence(name,seq);
CREATE UNIQUE INDEX "alliances_short_name_unique" on "alliances" ("short_name");

 */

// Need to auto-run this on startup within the container:
// php leaf db:migrate -vvv
class CreateAlliances extends Database
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        if (!static::$capsule::schema()->hasTable("alliances")) {
            static::$capsule::schema()->create("alliances", function (Blueprint $table) {
                $table->id('id');
                $table->string('short_name')->unique();
                $table->string('long_name');
                $table->longText('comment')->nullable();
            });
        }
        if (!static::$capsule::schema()->hasColumn("players","alliance_id")) {
            static::$capsule::schema()->table("players", function (Blueprint $table) {
                $table->longText('extra')->nullable();
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
