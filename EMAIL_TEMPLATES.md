# Recruitment email templates

## Administrator workflow

1. Open **Administration > Email Templates**. Six starter templates cover Round 1/2/3 invitations and Round 1/2/3 clearance messages.
2. Create or edit a template: name, unique code, round, purpose, subject, message format, body and active status. Choose **Formatted (Markdown)** for headings, bold text, lists and links, or **Plain text** for an unformatted message. Bodies support up to 20,000 characters. Deactivate obsolete templates instead of deleting them. Changes are audited. Re-running seeders preserves existing edits.
3. In **Candidates**, select one or more candidates and choose **Email selected (BCC)**. Select an active template. Selecting a different template replaces the subject/body in the composer.
4. Save all details directly in the template body to reuse the complete message without entering separate fields. The shared details section appears only if the subject/body contains placeholders; those are optional for templates that need variable content. Round number and round name come from the selected template.
5. Review the rendered subject and message preview, edit the message for this send if needed, then send. Editing the composer does not change the saved template. Every candidate receives the same message in BCC; To and Cc remain empty. Selecting one candidate also works.

Supported placeholders: `{{company_name}}`, `{{round_number}}`, `{{round_name}}`, `{{session_time}}`, `{{link}}`, `{{deadline}}`, `{{instructions}}`, `{{support_contact}}`, `{{exam_name}}`, `{{exam_key}}`, `{{exam_start_date}}`, `{{exam_end_date}}`.

Shared BCC messages cannot contain candidate-specific placeholders, credentials or private assessment links. For personalized messages, a separate per-candidate delivery feature is needed. Use the exam key field only for a common key intended for the whole selected group. Exam date fields use date pickers and render as YYYY-MM-DD; the end cannot precede the start. Session time/deadline are shared text: include the intended time zone and any cutoff time. Round 1 invitation emails schedule the round automatically at submission time, as described below; other rounds remain manually scheduled.

## Email history and automatic Round 1 scheduling

After the mail provider accepts an email using a template with **Round = 1** and **Purpose = Invitation**, each eligible selected candidate's Round 1 becomes **Scheduled**, with **Scheduled at** set to the current application date/time (Asia/Kolkata by default). This timestamp records invitation submission, not the date written in the email. An intentional resend sets it to the new submission time. Failed/uncertain sends and duplicate form submissions do not change schedules. Completed/cancelled rounds or downstream progress recorded during delivery are preserved and a scheduling-skipped activity is logged. Clearance messages, other-round invitations and custom emails without a selected template do not schedule Round 1.

The candidate list shows **Last email sent**, **Round 1 email sent**, **Round 1 scheduled at**, and **Round 1** status. Sent dates mean acceptance by the mail provider, not confirmed inbox delivery. Candidates without a recorded submission show **Not sent**.

Three tabs show candidate counts and matching records:

- **Email sent:** candidates with any successful email submission.
- **Round 1 email sent:** candidates with a successful Round 1 invitation submission, including those who have since completed the round.
- **Round 1 scheduled:** candidates currently in Scheduled status for Round 1, including manual scheduling.

Badges count non-deleted candidates visible to the current user, before table filters/search; the filtered table pagination shows the matching result total. HR only sees assigned candidates. Shared email addresses count as separate candidates although the batch sends one copy per unique address.

To find candidates still awaiting a Round 1 invitation, open **All candidates > Filters > Round 1 email > Not sent**. Choose **Sent** for those with a successful recorded Round 1 invitation, or **All candidates** to clear this filter. Combine it with Interest = Interested, position, HR or entry-date filters. Other email types do not count as Round 1 invitations. “Not sent” means no successful submission recorded; check any uncertain delivery attempt before retrying.

Migration `2026_10_29_160000_add_candidate_email_tracking` fills existing email dates from submitted batch history. Historical Round 1 invitations are identified using the linked template's current round/purpose; batches without a template only establish the general email date. Historical batches do not change interview schedules. If an email is accepted but subsequent scheduling fails, the submitted batch and sent dates remain recorded, and the UI asks for manual schedule correction without resending.

## Long company overview and assessment template

The focused `UiDeveloperEmailTemplateSeeder` adds the active **UI Developer - Round 1 overview and assessment** template, containing the supplied company overview, job description, responsibilities, technical/AI skills, three rounds, benefits, reply fields and HR contact details. It also upgrades the previously supplied, unchanged placeholder draft. Administrator edits are preserved when re-running the seeder.

All supplied details are saved directly: company `Spartan Infosoft Pvt. Ltd.`, test `SI UI Developer Test`, key `1009`, October 16–20, 2026 dates, and the IT Quiz app link. No shared fields need to be completed when sending this template. Edit the dates or any other details in **Administration > Email Templates** and save; subsequent selections load that saved content. The supplied dates are historical and have intentionally been retained as requested.

Formatted message syntax:

```markdown
## Assessment Details

- **Test Name:** {{exam_name}}
- **Exam Key:** {{exam_key}}

1. [Download the app]({{link}}).
2. Submit before **{{exam_end_date}}**.
```

Separate paragraphs and lists with blank lines. Preview the result in the candidate email composer before sending. Formatted emails contain an HTML version and a text fallback; the fallback retains readable Markdown notation. Raw HTML is stripped and unsafe link schemes are blocked. No file uploads are needed for these templates.

The create/edit template page also displays a formatted preview below the body. It refreshes after you leave the body field or change the format. Both previews apply document styling for headings, paragraph spacing, list markers and links. Use **Formatted (Markdown)** for Markdown content; plain-text mode intentionally displays the literal text.

## Automatic checks included

- Only active Super Admin users manage saved templates. Active HR Executives can use active templates and send emails to their assigned candidates. Both roles require candidate view/update permission; Round 1 invitations also require workflow permission for automatic scheduling. Authorization is enforced before any email attempt is recorded or sent.
- Template selection fills the subject/body/format; previews render shared values using the selected format.
- Missing values, unsupported/unresolved placeholders and invalid subject headers block sending.
- Invitation templates require earlier rounds to be Passed. The invited round cannot already be Passed, Failed or Cancelled. Withdrawn, rejected, joined or not-interested candidates cannot receive these invitation templates.
- Clearance templates require the matching round to be recorded as Passed for every selected candidate. Sending never changes an interview result or recruitment status.
- An invalid candidate blocks the whole batch; no silent skipping. Existing email validation, recipient deduplication and duplicate-submission protection remain in place.
- Submitted batches store the template ID, format and exact rendered subject/body, preserving a snapshot even if the template changes later. SMTP acceptance does not establish inbox delivery.
- Round 3 clearance is explicitly separate from an offer letter.

## Deployment

Back up the production database, upload all changed files, and review/approve pending migrations. This release also depends on the earlier candidate-outreach migrations. In the application folder:

```sh
php artisan migrate --force
php artisan db:seed --class=EmailTemplateSeeder --force
php artisan db:seed --class=UiDeveloperEmailTemplateSeeder --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Use the focused seeder above to add missing starter templates without resetting users, permissions or candidates. Configure SMTP as described in [Candidate outreach](CANDIDATE_OUTREACH.md). The default log mailer deliberately blocks sending. No actual candidate emails are sent during development tests.

## Suggested next improvements

| Priority | Improvement | Approval/control |
| --- | --- | --- |
| 1 | A session record holding the assessment/interview link, date, deadline and instructions; populate emails from it | HR selects the session; admin reviews recipients and preview |
| 2 | Suggest the appropriate clearance email after a verified round pass | Create a draft/review task; do not send on every status save |
| 3 | Invitation history with provider delivery/bounce status and an explicit resend reason | Use provider events; never infer delivery from SMTP acceptance |
| 4 | Reminders for invited candidates who have not completed the assessment | Stop on completion, withdrawal or contact refusal; deduplicate by candidate/session/reminder |
| 5 | Approved per-candidate templates for personalized links and greetings | Separate messages, with recipient-specific preview and delivery tracking |
| 6 | Offer-letter drafts after Round 3 passes | Admin verifies compensation, position and joining date before release |

These improvements are proposals. This module does not enable scheduled sending, provider webhooks, personalized mail or offer generation. Reliable automated sending should use a delivery outbox and bounded scheduled processing, with explicit handling of uncertain delivery to avoid duplicates.
