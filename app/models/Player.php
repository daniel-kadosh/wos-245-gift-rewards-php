<?php
namespace App\Models;
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
        'avatar_image', 'stove_lv', 'stove_lv_content'
    ];

    /**
     * Indicates if the model should be timestamped.
     * @var bool
     */
    public $timestamps = true;
}
