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
    "giftcode_ids" text not null default '',
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
    "send_gift_ts" integer not null default '0',
    "pct_done" integer not null default '100');
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
        if (!static::$capsule::schema()->hasColumn("giftcodes","pct_done")) {
            static::$capsule::schema()->table("giftcodes", function (Blueprint $table) {
                $table->integer('send_gift_ts')->default(0);
                $table->integer('pct_done')->default(100);
            });
        }
        if (!static::$capsule::schema()->hasColumn("players","giftcode_ids")) {
            static::$capsule::schema()->table("players", function (Blueprint $table) {
                $table->text('giftcode_ids')->default('');
            });
        }

        /* PDO somehow doesn't like these queries, so run manually with sqlite3 client
         *
        CREATE temporary table 'foo' as
                    SELECT id as pid,substr(last_message,1,instr(last_message,':')-1) as code
                        from players
                        where last_message like '%: redeemed succesfully'
                            or last_message like '%: already used';

        UPDATE players set giftcode_ids='@'
            FROM (select p.id from players p
                    left join foo on foo.pid=p.id
                    where foo.pid is null) as f
            where f.id=players.id and players.giftcode_ids='';

        UPDATE players set giftcode_ids=f.gid
                    FROM (SELECT pid,concat('@',g.id,'@') as gid from foo
                            join giftcodes g on g.code=foo.code) as f
                            where f.pid=players.id and giftcode_ids='';

        drop table foo;

        */
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
