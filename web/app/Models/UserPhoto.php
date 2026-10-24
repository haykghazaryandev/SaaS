<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A profile photo (base64 WebP), served at /avatars/{user_id}-{token}.webp. */
class UserPhoto extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'token', 'data'];
}
