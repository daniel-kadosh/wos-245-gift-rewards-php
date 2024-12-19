<?php
use Leaf\Schema;
use Leaf\Database;
use Illuminate\Database\Schema\Blueprint;

/*
 * Sqlite schema after this:
 *
CREATE TABLE IF NOT EXISTS "players"
    ("id" integer not null,
    "player_name" varchar not null,
    "last_message" varchar not null,
    "avatar_image" varchar not null,
    "stove_lv" integer not null,
    "stove_lv_content" varchar not null,
    "created_at" datetime,
    "updated_at" datetime,
    "extra" text,
    primary key ("id"));

CREATE TABLE IF NOT EXISTS "alliances"
    ("id" integer not null primary key autoincrement,
    "short_name" varchar not null,
    "long_name" varchar not null,
    "comment" text);
CREATE UNIQUE INDEX "alliances_short_name_unique" on "alliances" ("short_name");

CREATE TABLE IF NOT EXISTS "giftcodes"
    ("id" integer not null primary key autoincrement,
    "code" varchar not null,
    "statistics" text not null default '{}',
    "created_at" datetime,
    "updated_at" datetime,
    "send_gift_ts" integer not null default '0');
CREATE UNIQUE INDEX "giftcodes_code_unique" on "giftcodes" ("code");

 */

// Need to auto-run this on startup within the container:
// php leaf db:migrate -vvv
class UpdateGiftcodes extends Database
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        if (!static::$capsule::schema()->hasColumn("giftcodes","send_gift_ts")) {
            static::$capsule::schema()->table("giftcodes", function (Blueprint $table) {
                $table->integer('send_gift_ts')->default(0);
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
