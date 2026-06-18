<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalNormativeSetting extends Model
{
    protected $fillable = [
        'user_id', 'column',
        'kip_linia_bk_months', 'kip_linia_3_months', 'kip_linia_2_months', 'kip_linia_1_months', 'kip_linia_add_months',
        'kip_manevry_months', 'kip_manevry_alternation',
        'kip_podem_months',
        'kip_kru_months', 'kip_ars_r_months', 'kip_pnevmatika_months', 'kip_scep_months',
        'atz_months', 'atz_line_months',
    ];

    protected $casts = [
        'kip_manevry_alternation' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
