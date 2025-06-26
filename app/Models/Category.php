<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
        'name',
        'color'
    ];

    public function items()
    {
        return $this->hasMany(ReceiptItem::class, 'category', 'name');
    }
}
