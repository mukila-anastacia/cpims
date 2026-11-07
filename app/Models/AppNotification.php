<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class AppNotification extends Model
{
    protected $table = 'notifications';
    protected $guarded = [];
    protected $casts = ['is_read' => 'boolean'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
