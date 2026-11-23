# Mail accounts

The application uses three independent mailers. Sender selection is based on the email template's round, not the subject or candidate's latest status.

| Email | Mailer | Environment prefix | Default sender |
| --- | --- | --- | --- |
| Round 1 templates (invitation and cleared) | `invitation` | `INVITATION_MAIL_` | invitation@spartaninfosoft.com |
| Round 2, Round 3, custom candidate messages, and other application emails | `careers` | `MAIL_` | careers@spartaninfosoft.com |
| Offer letters | `hr` | `HR_MAIL_` | hr@spartaninfosoft.com |

Each profile has its own `MAILER`, `SCHEME`, `HOST`, `PORT`, `USERNAME`, `PASSWORD`, `FROM_ADDRESS`, and `FROM_NAME`. See `.env.example` for all keys. `MAIL_MAILER` controls the careers transport; Laravel's default mailer is `careers`. It must contain a transport such as `smtp` or `log`, not the name `careers`.

Enter the SMTP host, port, username and password for each mailbox, then set its `MAILER` to `smtp`. Use `SCHEME=smtps` for implicit TLS (typically port 465); `SCHEME=smtp` or `null` uses SMTP with automatic STARTTLS when offered (typically port 587). Credentials are independent: invitation and HR do not fall back to the careers username or password. Keep passwords only in the untracked `.env` or deployment secret store.

The local environment's existing HR SMTP settings were moved to `HR_MAIL_*`. Careers and Invitation start in `log` mode with empty passwords until their accounts are configured. The application blocks candidate and offer sending through `log` or `array` transports, so these cannot be reported as delivered.

`MAIL_TO_ADDRESS` remains an optional company To mailbox for candidate BCC emails. When blank, To uses the selected sender. The admin preview shows both From and To. Candidate recipients stay in BCC; offer letters are addressed individually to each candidate.

For new candidate email submissions, Administration → Activity logs records each candidate's name and email address, From and company To addresses, rendered subject and message source, body format, sending admin, submission time, and batch reference. Filter Activity type to Candidate email submitted and use Details to read the content. Search by candidate name, email, or subject. These are snapshots, so later candidate or template edits do not change them. Submission means the provider accepted the message, not confirmed inbox delivery. The email audit persists even if subsequent interview scheduling fails. Older activity entries retain their original recorded fields. No extra To/CC recipient is needed for tracking, and candidate addresses remain private in BCC.

After environment changes, run `php artisan config:clear` for local development, or rebuild the production configuration with `php artisan config:cache`. Restart long-running queue workers if used. No database migration is required. Verify delivery only after all selected accounts are configured.

Tests use in-memory transports and do not send real email:

`php vendor/phpunit/phpunit/phpunit --filter="CandidateOutreachTest|EmailTemplateTest|OfferLetterTest" --no-progress`
