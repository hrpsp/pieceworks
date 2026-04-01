<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StyleSku extends Model
{
    use HasFactory;

    protected $table = 'style_sku';

    protected $fillable = [
        'style_code',
        'style_name',
        'complexity_tier',
    ];
}
