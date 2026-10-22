<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateCodeSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'next_number' => 'integer'];
    }
}
