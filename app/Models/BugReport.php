<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BugReport extends Model
{
    protected $fillable = [
        'user_id',
        'description',
        'screenshot_path',
        'page_url'
    ];
    public function user() { return $this->belongsTo(User::class); }

}
