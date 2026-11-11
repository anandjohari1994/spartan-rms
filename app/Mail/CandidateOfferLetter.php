<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CandidateOfferLetter extends Mailable
{
    public function __construct(public array $details, private string $pdf, private string $filename) {}

    public function build(): static
    {
        return $this->subject('Conditional Offer of Employment - Spartan Infosoft Pvt. Ltd.')
            ->text('mail.candidate-offer-letter')
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }
}
