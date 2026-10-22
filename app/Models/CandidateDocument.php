<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateDocument extends Model
{
    use Concerns\BelongsToCandidate;

    protected $guarded = ['*'];

    protected $hidden = ['private_path'];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
