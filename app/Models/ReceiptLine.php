<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class ReceiptLine extends Model
{
    protected $guarded = [];
    protected $casts = ['qty_received' => 'decimal:2'];

    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class); }
    public function poLine(): BelongsTo { return $this->belongsTo(PoLine::class); }
}
