<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
class PurchaseOrder extends Model
{
    protected $guarded = [];
    protected $casts = ['total' => 'decimal:2', 'expected_delivery' => 'date', 'issued_at' => 'datetime'];

    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class); }
    public function quotation(): BelongsTo { return $this->belongsTo(Quotation::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function lines(): HasMany { return $this->hasMany(PoLine::class); }
    public function receipts(): HasMany { return $this->hasMany(GoodsReceipt::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
}
