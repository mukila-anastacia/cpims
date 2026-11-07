<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class PoLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:2', 'unit_price' => 'decimal:2'];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function requestLine(): BelongsTo { return $this->belongsTo(RequestLine::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function receiptLines(): HasMany { return $this->hasMany(ReceiptLine::class); }
}
