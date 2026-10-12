<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Media extends Model
{
    protected $fillable = ['collection', 'disk', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
