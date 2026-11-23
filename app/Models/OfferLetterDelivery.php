<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OfferLetterDelivery extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'submitted_at' => 'datetime'];
    }

    public function offerLetter()
    {
        return $this->belongsTo(OfferLetter::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
