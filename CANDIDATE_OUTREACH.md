# Candidate outreach and workflow automation

## Implemented actions

On Candidates, select rows and use **Email selected (BCC)**. Active Super Admins can email accessible candidates; active HR Executives can email only candidates assigned to them. Choose one candidate or up to 100. The server checks every candidate before sending; a mixed authorized/unauthorized selection is rejected entirely. HR can select an active saved template and review/edit the outgoing message without changing the saved template. Template management and **Update selected statuses** remain admin-only. Deleted candidates cannot be included.

Email opens a composer for one shared subject and plain-text or formatted message. All candidate addresses are BCC only; To and Cc are empty. Duplicate addresses receive one copy. Missing/invalid addresses block the whole selection before sending. Use the Interested filter to select assessment invitees. Include the assessment link, date, time zone, deadline, duration and support contact; do not include individual credentials in a shared message. A successful Round 1 invitation template schedules the first round at the current date/time. Interview results are not changed. See [Email templates](EMAIL_TEMPLATES.md) for the list columns, email-history counts and scheduling rules.

The batch table tracks sending/submitted/uncertain attempts, and candidate activity logs record successful submission to the mail provider. Submitted is not confirmation of inbox delivery. A unique submission token prevents re-executing the same form submission. An uncertain attempt must be checked with the mail provider before composing another invitation; a new composer creates a new attempt. A process interrupted while sending can leave a batch in sending state. Do not automatically retry it.

Bulk status changes use the existing recruitment statuses and interview gates. Selected/Joined requires three passed rounds, or an administrator's explicit override reason. Every candidate change is audited. If any candidate fails validation, the whole operation rolls back. Interview statuses, calling statuses and interest are separate from recruitment status.

Each row also has **WhatsApp**. Active administrators and the candidate's authorized HR owner can compose a message and open a prefilled chat. They must press Send in WhatsApp. This is not a bulk broadcast or Business API integration, and opening a chat is not recorded as a successful send.

## Deployment

Upload the application changes, mail view and new migration. Back up the production database and review/approve the additive migration before running:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Configure a real mail provider in the server `.env`; the default log mailer deliberately blocks the send action. Use provider-supplied host, port and scheme, for example:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=<provider-smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<mailbox-username>
MAIL_PASSWORD=<mailbox-password>
MAIL_FROM_ADDRESS=<verified-sender-address>
MAIL_FROM_NAME="Spartan Infosoft Recruitment"
```

Use the provider's required TLS settings (for implicit TLS on port 465, the scheme is commonly `smtps`). Verify sender-domain authentication with the provider. Rebuild config cache after changes. No SMTP credentials are stored in source control. Sending is synchronous for the current shared-hosting setup; use smaller selections if the provider has a lower recipient limit. Test with controlled mailboxes before sending real invitations. No real candidate messages were sent during implementation.

## Suggested next automation phases

These are proposals, not implemented workflow changes. The existing system has three interview rounds but no certification lifecycle or offer-letter approval workflow.

| Stage | Automation to add | Human decision retained |
| --- | --- | --- |
| Shortlist and interest call | HR work queue with next action, owner and due date; prevent duplicate follow-ups | HR records interest and shortlists |
| Round 1 scheduling | Select assessment session once; generate invitation and follow-up due time | HR confirms session; admin approves batch send |
| Round 1 exam | Assessment-provider webhook or reviewed CSV import records completion and score; stop reminders on completion | HR confirms pass/fail and handles exceptions |
| Round 2 scheduling | After confirmed Round 1 pass, create HR scheduling task and invitation draft | HR picks interviewer and slot |
| Certification discussion | After confirmed Round 2 pass, create call task and course-information template | HR explains course terms; candidate chooses whether to attend |
| Certification attendance | Track Not Discussed, Interested, Enrolled, In Progress, Completed, Declined separately; reminders for agreed dates | Authorized staff verifies completion evidence |
| Round 3 | Verified certification completion creates a Round 3 scheduling task | HR confirms slot and interview outcome |
| Offer | After approved Round 3 pass, generate an offer draft from a versioned template | Admin verifies role, compensation and joining date, then approves release |
| Overdue work | Daily HR task list, manager overdue summary, and next-action dashboard | HR decides escalation, rescheduling or closure |

Start with next-action tasks, reusable message templates, and certification tracking. Then integrate assessment completion and reminders. Add approved offer generation last. Keep certification separate from employment selection; attendance alone should never issue an offer or mark an interview passed.

For automated reminders, add a delivery outbox, deduplication keys, cancellation when a candidate withdraws or completes a stage, contact preferences, delivery failure handling and scheduled processing. Hostinger cron can trigger scheduled batches; no permanent worker is required if jobs are bounded. Per-candidate links or credentials require individual messages rather than a shared BCC invitation. WhatsApp automation would require a separate approved provider integration; the current click-to-chat feature cannot verify delivery or automate reminders.
