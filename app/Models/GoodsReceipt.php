<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class GoodsReceipt extends Model
{
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime'];

    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function overrideUser(): BelongsTo { return $this->belongsTo(User::class, 'override_by'); }
    public function lines(): HasMany { return $this->hasMany(ReceiptLine::class); }
}
