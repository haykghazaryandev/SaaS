<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Base model for app models that have factories. */
abstract class HasFactoryModel extends Model
{
    use HasFactory;
}
