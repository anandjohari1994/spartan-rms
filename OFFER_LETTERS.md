# Conditional offer letters

Open `/admin/offer-letters` from **Offer Letter** in the sidebar.

1. Candidates must have both Round 1 and Round 2 marked **Passed**. The conditional template says the client interview is pending, so candidates whose Round 3 is Passed, Failed, or Cancelled are excluded. Deleted, rejected, withdrawn, joined, and not-interested candidates are also excluded. HR executives see only their assigned candidates; administrators see all eligible candidates.
2. Choose **Prepare offer**, complete the address, position, dates, annual package (CTC), signatory, and optional clause choice, then **Save draft PDF**. The candidate's profile supplies their name and email. Enter the annual package in rupees (for example, `600000` for 6 lakh); the monthly and annual salary table updates automatically. No compensation or employment dates are inferred from expected CTC.
3. Use **Preview** to open the saved, five-page PDF. The browser PDF viewer supports printing and downloading. Preview URLs require authentication and candidate access. Files are stored on the private disk.
4. Choose **Send offer**, or select up to 25 candidates and choose **Send selected offer letters**. Each candidate receives a separate email with only their own PDF attached. Every selected candidate must have a valid, current draft before any message is sent. The confirmation captures the draft revisions; changed revisions are rejected.

## Automatic salary calculation

The configurable company allocation in `config/offer_letters.php` uses basic at 50% of gross, HRA at 40% of basic, employer PF at 12% of basic capped at INR 3,000 per month, and the remaining gross salary as special allowance. LTA, medical, conveyance, and other allowances are zero in this default split. The annual CTC includes employer PF. These allocation percentages are company defaults, not a universal statutory salary structure. The PF ceiling uses the [official September 2026 revision](https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313790&lang=2&reg=48) to INR 25,000 of monthly wages. This is an offer allocation, not a payroll, tax, eligibility, ESI, gratuity, or take-home calculation.

The server recalculates components from annual CTC, ignoring any submitted component values. Both monthly and annual columns add up exactly. Annual amounts preserve the entered package to the paise; monthly rounding residuals go into special allowance. The saved draft includes the policy and calculated figures, so later configuration changes do not alter its PDF. Older monthly-entry drafts keep their PDF and are converted only when explicitly edited and saved.

## Delivery and recovery

The configured delivery mailer is used. `log` and `array` transports cannot be reported as delivery. The feature does not change candidate recruitment or interview statuses.

`draft` means the letter can be edited. `submitted` means the mail provider accepted it, not that inbox delivery is confirmed. `sending` and `uncertain` prevent duplicate submissions, including repeated clicks and stale browser forms. A selected batch is reserved in a committed transaction before delivery; send actions explicitly disable Filament's outer database transaction.

If a delivery is unconfirmed or remains marked sending after a server interruption, check `storage/logs/laravel.log`, the activity log, and the mail provider. Do not automatically reset these states or resend: the provider may have accepted the message. Sent and unconfirmed letters remain immutable. Reissuing an offer is intentionally not exposed by this workflow.

## Template and deployment

`resources/offer-letters/reference.pdf` is the supplied reference, preserved unchanged. `conditional-offer.pdf` is a prepared copy with dynamic text removed. `layout.json` maps replacements onto the original pages. The original branding, fixed wording, pagination, and salary table remain intact. The application uses the matching OFL-licensed Carlito fonts in `resources/fonts`; the license is included.

Candidate-specific lines are filled in, with limited font scaling for long values. Values that would fall below 7.5 pt are rejected rather than clipped. The form explicitly resolves optional clause 5.6; its internal HR editorial note is never included in the issued PDF. Candidate signature, acceptance date, and place remain blank for the candidate to complete.

Deploy with `composer install --no-dev --optimize-autoloader` and `php artisan migrate --force`, including `resources/fonts` and `resources/offer-letters`. PHP generates the PDFs; production does not need Python, Poppler, a browser, or a queue worker for this feature. The existing private storage disk must be writable. Back up it and the `offer_letters` table together.

For template maintenance only, install `pdfplumber` and `PyMuPDF` in a development Python environment. From the repository root run `python tools/offer-letters/build_layout.py`, then `python tools/offer-letters/prepare_template.py`. This layout builder is specific to the supplied five-page reference; a different reference requires revising its field mapping and visually checking every page. Preserve the original source PDF.

## Validation

`php vendor/phpunit/phpunit/phpunit --filter=OfferLetterTest` covers eligibility, ownership, authenticated previews, personal PDF attachments, all-selection preflight, stale drafts, changed recipients, expiration, tampering, duplicate submissions, failures, salary totals, and non-delivery mailers. All mail in these tests uses an in-memory transport. Browser checks use a separate SQLite fixture with synthetic candidates and an array mailer.
