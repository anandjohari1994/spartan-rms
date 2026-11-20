# Conditional offer letters

Open `/admin/offer-letters` from **Offer Letter** in the sidebar.

1. Candidates must have both Round 1 and Round 2 marked **Passed**. The conditional template says the client interview is pending, so candidates whose Round 3 is Passed, Failed, or Cancelled are excluded. Deleted, rejected, withdrawn, joined, and not-interested candidates are also excluded. HR executives see only their assigned candidates; administrators see all eligible candidates.
2. Choose **Prepare offer**, complete the address, position, dates, annual package (CTC), signatory, and optional clause choice, then **Save draft PDF**. The candidate's profile supplies their name and email. Enter the annual package in rupees (for example, `600000` for 6 lakh); the monthly and annual salary table updates automatically. No compensation or employment dates are inferred from expected CTC.
3. Use **Preview** to open the saved PDF. The revised letter has six pages, including employment terms, company benefits and the salary annexure. The browser PDF viewer supports printing and downloading. Preview URLs require authentication and candidate access. Files are stored on the private disk.
4. Choose **Send offer**, or select up to 25 candidates and choose **Send selected offer letters**. Each candidate receives a separate email with only their own PDF attached. Every selected candidate must have a valid, current draft before any message is sent. The confirmation captures the draft revisions; changed revisions are rejected.

## Preparation defaults and employment terms

New offers prefill Software Development, Remote (Work From Home), Full-Time, three months' probation, today's letter date, INR 300,000 annual CTC, salary payment on the 1st of the following month, Shaurya Johari / HR Manager, and inclusion of clause 4.7. These are editable defaults in `config/offer_letters.php`; saved draft values take precedence when reopening.

Selecting a proposed joining date fills commencement with the preceding Monday-Friday day (Monday, Saturday and Sunday all use the preceding Friday). Public holidays are not excluded. Document submission and offer validity follow commencement; acceptance follows validity. Subsequent changes update blank fields and values still matching the previous automatic date. Manually overridden dates are retained. Clearing a source clears still-linked dates. Dates must remain on or after the letter date and acceptance cannot exceed offer validity.

Sections 6.8-6.10 include editable notice periods, working hours/days, and leave policy. Defaults are one month's notice both during probation and after confirmation, nine hours daily, and five days weekly (Monday-Friday). The leave paragraph covers annual/earned, casual and sick leave, holidays, approval, accrual, carry-forward and encashment under company policy and applicable law. It preserves statutory rights without inventing a universal industry leave quota; the [Maharashtra Act, section 18](https://www.indiacode.nic.in/bitstream/123456789/19710/1/shops_and_establishments.pdf) specifies entitlements and eligibility separately. HR can replace this wording with the company's approved policy. Long terms wrap as justified paragraphs; text exceeding the reserved space is rejected instead of clipped. New terms are added to older drafts only when explicitly edited and saved.

## Automatic salary calculation

The configurable company allocation in `config/offer_letters.php` uses basic at 50% of gross, HRA at 40% of basic, employer PF at 12% of basic capped at INR 3,000 per month, and the remaining gross salary as special allowance. LTA is 5% of gross, medical and conveyance are each 2.5% of gross, and other allowance is 5% of gross. These configurable allocations reduce special allowance and do not increase CTC. The annual CTC includes employer PF. These allocation percentages are company defaults, not a universal statutory salary structure. The PF ceiling uses the [official September 2026 revision](https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313790&lang=2&reg=48) to INR 25,000 of monthly wages. This is an offer allocation, not a payroll, tax, eligibility, ESI, gratuity, or take-home calculation.

The server recalculates components from annual CTC, ignoring any submitted component values. Both monthly and annual columns add up exactly. Annual amounts preserve the entered package to the paise; monthly rounding residuals go into special allowance. The saved draft includes the policy and calculated figures, so later configuration changes do not alter its PDF. Older monthly-entry drafts keep their PDF and are converted only when explicitly edited and saved.

## Delivery and recovery

The configured delivery mailer is used. `log` and `array` transports cannot be reported as delivery. The feature does not change candidate recruitment or interview statuses.

`draft` means the letter can be edited. `submitted` means the mail provider accepted it, not that inbox delivery is confirmed. `sending` and `uncertain` prevent duplicate submissions, including repeated clicks and stale browser forms. A selected batch is reserved in a committed transaction before delivery; send actions explicitly disable Filament's outer database transaction.

If a delivery is unconfirmed or remains marked sending after a server interruption, check `storage/logs/laravel.log`, the activity log, and the mail provider. Do not automatically reset these states or resend: the provider may have accepted the message. Sent and unconfirmed letters remain immutable. Reissuing an offer is intentionally not exposed by this workflow.

## Template and deployment

`resources/offer-letters/reference.pdf` is the supplied reference, preserved unchanged. `revised-reference.pdf` applies the November 19 wording and formatting edits, with consecutive section numbers and updated cross-references. `conditional-offer.pdf` is its prepared copy with dynamic text removed. `layout.json` maps replacements onto these pages. The original branding and salary table are retained. Body paragraphs are justified; headings, addresses, and table labels retain their natural alignment. The supplied authorized-signature.png is embedded above the authorized signatory name at 132 pt wide, with proportional height (about 75 pt). The application uses the matching OFL-licensed Carlito fonts in `resources/fonts`; the license is included.

Candidate-specific lines are filled in, with limited font scaling for long values. Values that would fall below 7.5 pt are rejected rather than clipped. The form explicitly resolves optional clause 4.7; its internal HR editorial note is never included in the issued PDF. Candidate signature, acceptance date, and place remain blank for the candidate to complete. Reporting Manager is no longer requested or printed. The old conditional-employment section is merged into the client-interview section. Client conditions and the unboxed billing paragraph use regular weight. Section 8 contains only the personalized acknowledgement and acceptance sentence. Clause 7.3 contains the requested restriction on sharing or disclosing the offer letter without prior written company authorization.

Existing PDFs are immutable snapshots of the terms previously prepared. To apply the revised template to an unsent draft, choose **Edit draft**, then **Save draft PDF**, and preview the new revision. Previously sent or unconfirmed offers are not rewritten.

Deploy with `composer install --no-dev --optimize-autoloader` and `php artisan migrate --force`, including `resources/fonts` and `resources/offer-letters`. PHP generates the PDFs; production does not need Python, Poppler, a browser, or a queue worker for this feature. The existing private storage disk must be writable. Back up it and the `offer_letters` table together.

For template maintenance only, install `pdfplumber`, `reportlab`, and `PyMuPDF` in a development Python environment. From the repository root run `python tools/offer-letters/build_reference.py`, `python tools/offer-letters/build_layout.py`, then `python tools/offer-letters/prepare_template.py`, stopping if any step fails. These builders are specific to the supplied reference; a different reference requires revising the content mapping and visually checking every page. Preserve the original source PDF. Validate a generated sample with `python tools/offer-letters/check_pdf.py path/to/sample.pdf` as well as rendering all pages.

## Validation

`php vendor/phpunit/phpunit/phpunit --filter=OfferLetterTest` covers eligibility, ownership, authenticated previews, personal PDF attachments, all-selection preflight, stale drafts, changed recipients, expiration, tampering, duplicate submissions, failures, salary totals, and non-delivery mailers. All mail in these tests uses an in-memory transport. Browser checks use a separate SQLite fixture with synthetic candidates and an array mailer.
