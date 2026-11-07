<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class InvoiceLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2'];

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function poLine(): BelongsTo { return $this->belongsTo(PoLine::class); }
}
