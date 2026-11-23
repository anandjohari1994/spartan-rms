<div class="space-y-4">
    @php($email = app(\App\Services\EmailActivityDetails::class)->forRecord($record))
    @if ($email)
        <dl class="space-y-2 text-sm" style="overflow-wrap: anywhere">
            @foreach ([
                'Candidate' => $email['candidate_name'],
                'Candidate email ('.($email['recipient_type'] ?? 'BCC').')' => $email['candidate_email'],
                'From' => $email['from'],
                (($email['recipient_type'] ?? 'BCC') === 'BCC' ? 'Company To mailbox' : 'To') => $email['to'],
                'Sent by' => $record->actor?->name ?? 'Unavailable',
                'Submitted at' => $email['submitted_at'] ?? 'Not confirmed at this event',
                'Subject' => $email['subject'],
                'Message format' => $email['body_format'],
                'Batch reference' => $email['batch'] ?? null,
                'Offer ID' => $email['offer_id'] ?? null,
                'Revision' => $email['revision'] ?? null,
                'Delivery ID' => $email['delivery_id'] ?? null,
                'Message ID' => $email['message_id'] ?? null,
                'PDF attachment' => $email['attachment'] ?? null,
                'PDF SHA-256' => $email['attachment_sha256'] ?? null,
            ] as $label => $value)
                @if ($value !== null)
                    <div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endif
            @endforeach
        </dl>
        @if (($email['status'] ?? null) === 'submitted')
            <p class="text-sm">Submitted to provider. This does not confirm inbox delivery or that the candidate read the email.</p>
        @else
            <p class="text-sm">{{ ($email['status'] ?? null) === 'sending' ? 'Sending was started at this event.' : 'Delivery was unconfirmed at this event.' }} Check the submission event for the final outcome.</p>
        @endif
        @if ($email['reconstructed'] ?? false)
            <p class="text-sm">The recipient and offer details come from the saved delivery record. The original email subject, content, and From address were not stored in this activity entry. The subject and content below are a preview reconstructed using the current email template, and may differ from the email sent.</p>
        @endif
        <h3 class="font-semibold">{{ ($email['reconstructed'] ?? false) ? 'Email content preview (reconstructed)' : 'Email content' }}</h3>
        <pre class="whitespace-pre-wrap text-sm" style="overflow-wrap: anywhere; font-family: inherit">{{ $email['body'] }}</pre>
    @else
        <h3 class="font-semibold">Previous values</h3>
        <pre class="whitespace-pre-wrap text-sm" style="overflow-wrap: anywhere">{{ json_encode($record->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        <h3 class="font-semibold">New values</h3>
        <pre class="whitespace-pre-wrap text-sm" style="overflow-wrap: anywhere">{{ json_encode($record->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</div>
