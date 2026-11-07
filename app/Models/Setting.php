<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class Setting extends Model
{
    protected $primaryKey = 'name';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    /** Read one setting, for example Setting::get('duplicate_window_days', 14). */
    public static function get(string $name, mixed $default = null): mixed
    {
        return static::find($name)?->value ?? $default;
    }
}
