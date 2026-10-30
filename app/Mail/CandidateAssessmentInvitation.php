<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Mail\Mailable;

class CandidateAssessmentInvitation extends Mailable
{
    public function __construct(public string $messageSubject, public string $messageBody, public string $bodyFormat = 'plain') {}

    public function build(): static
    {
        $this->subject($this->messageSubject)->text('mail.candidate-assessment');
        if ($this->bodyFormat === 'markdown') {
            $this->view('mail.candidate-assessment-html', ['messageHtml' => EmailTemplateService::safeHtml($this->messageBody)]);
        }

        return $this;
    }
}
