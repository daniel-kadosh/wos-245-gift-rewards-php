<?php

namespace App\Models;

// CREATE TABLE players ( player_id varchar(255), player_name varchar(255), last_message varchar(255) );

class Player extends Model
{
    protected $table = 'players';
    protected $primaryKey = 'id';
    protected $incrementing = false;

    /**
     * The attributes that are mass assignable.
     * @var array
     */
    protected $fillable = [
        'id', 'player_name', 'last_message',
    ];

    /**
     * Indicates if the model should be timestamped.
     * @var bool
     */
    public $timestamps = true;

}
