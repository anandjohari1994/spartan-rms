<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfferLetter extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'submitted_at' => 'datetime'];
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }
}
