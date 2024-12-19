<?php
use Leaf\Schema;
use Leaf\Database;
use Illuminate\Database\Schema\Blueprint;

/*
CREATE TABLE IF NOT EXISTS "giftcodes" (
    "id" integer not null primary key autoincrement,
    "code" varchar not null,
    "statistics" longtext not null default '{}',
    "created_at" datetime,
    "updated_at" datetime
);
CREATE UNIQUE INDEX "giftcodes_code_unique" on "giftcodes" ("code");
*/

// Need to auto-run this on startup within the container:
// php leaf db:migrate -vvv
class CreateGiftcodes extends Database
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        if (!static::$capsule::schema()->hasTable("giftcodes")) {
            static::$capsule::schema()->create("giftcodes", function (Blueprint $table) {
                $table->id('id');
                $table->string('code')->unique();
                $table->longtext('statistics')->default('{}');
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
