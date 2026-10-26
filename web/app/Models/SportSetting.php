<?php

namespace App\Models;

use App\Enums\Sport;
use Illuminate\Database\Eloquent\Model;

/** A row of `sports`: whether players see the sport (admin → Sports). What a sport is lives in App\Enums\Sport. */
class SportSetting extends Model
{
    protected $table = 'sports';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'visible', 'sort'];

    protected function casts(): array
    {
        return ['visible' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Sport::forgetVisible());
    }

    public function sport(): ?Sport
    {
        return Sport::tryFrom($this->key);
    }
}
