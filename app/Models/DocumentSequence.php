<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    public $timestamps = false;

    protected $fillable = ['prefix', 'year', 'last_number'];
}
