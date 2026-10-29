<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class CandidateAssessmentInvitation extends Mailable
{
    public function __construct(public string $messageSubject, public string $messageBody) {}

    public function build(): static
    {
        return $this->subject($this->messageSubject)->text('mail.candidate-assessment');
    }
}
