<?php

namespace App\Services;

use App\Mail\CandidateOfferLetter;
use App\Models\ActivityLog;
use App\Models\OfferLetterDelivery;

class EmailActivityDetails
{
    public function forRecord(ActivityLog $record): ?array
    {
        $values = $record->new_values ?? [];
        if (in_array($record->event, ['candidate.email_submitted', 'offer.submitted']) && isset($values['candidate_email'])) {
            return $values;
        }

        $status = match ($record->event) {
            'offer.submitted' => 'submitted',
            'offer.sending', 'offer.resending' => 'sending',
            'offer.delivery_unconfirmed' => 'uncertain',
            default => null,
        };
        if (! $status || empty($values['delivery_id']) || empty($values['offer_id'])) {
            return null;
        }

        // Only use the referenced delivery snapshot, never the current offer draft.
        $delivery = OfferLetterDelivery::whereKey($values['delivery_id'])
            ->where('offer_letter_id', $values['offer_id'])
            ->when(isset($values['revision']), fn ($query) => $query->where('revision', $values['revision']))
            ->first();
        if (! $delivery) {
            return null;
        }

        $details = $delivery->details;
        $canPreview = isset($details['candidate_name'], $details['designation'])
            && (($details['offer_terms_version'] ?? 1) >= 2 || isset($details['acceptance_deadline']));

        return [
            ...$values,
            'candidate_name' => $details['candidate_name'] ?? 'Not recorded',
            'candidate_email' => $delivery->recipient,
            'from' => 'Not recorded in this activity entry',
            'to' => $delivery->recipient, 'recipient_type' => 'To',
            'subject' => CandidateOfferLetter::SUBJECT,
            'body' => $canPreview ? view('mail.candidate-offer-letter', ['details' => $details])->render() : 'Email content is unavailable.',
            'body_format' => 'plain', 'status' => $status,
            'submitted_at' => $status === 'submitted' ? $delivery->submitted_at?->toIso8601String() : null,
            'attachment_sha256' => $delivery->pdf_sha256,
            'reconstructed' => true,
        ];
    }
}
