<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['company_name', 'contact_person', 'phone', 'email', 'address', 'notes', 'status'];

    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }
}
