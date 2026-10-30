# HR daily workflow - 29 October 2026

This guide supplements the 22 October HR manual with the simplified entry and call workflow.

## 1. Record call beside each candidate

Select **Record call** on the Candidates list or the candidate's View page. The candidate is already selected. Enter the actual call time, outcome and notes. For Connected calls, optionally update interest; leaving it blank preserves the current value. Choose None, Call again, or Schedule Round 1/2/3 as the next action.

Scheduling a round requires its appointment time and a separate follow-up date. Existing interview progression rules apply. All call, interest, scheduling and task changes save together or roll back together. Messages are not sent automatically. Use the separate WhatsApp action or coordinate email with the administrator.

## 2. Save candidate & record call

The create form now offers **Save candidate** and **Save candidate & record call**. The second saves the candidate and opens their View page with the Record call form. Cancelling that popup leaves the saved candidate intact and does not invent a call record. A validation failure on the candidate form keeps the form open.

## 3. Follow-ups within the conversation

Use **Record call** on a pending row in Follow Ups. Saving the conversation completes that source task and, if requested, creates the next task. Other pending tasks are preserved. Selecting None closes the source without adding another task. The same submitted form cannot be replayed to duplicate calls/tasks.

Use **Assessment follow-up** when specifically checking whether the online exam was completed. That action additionally tracks reported completion and result verification as described in [Assessment follow-ups](ASSESSMENT_FOLLOW_UPS.md). General Record call does not mark an assessment complete.

## 4. Daily work list

Candidates has tabs for **All candidates**, **Not Called**, **Due Today**, **Overdue**, **Interested** and **Upcoming Interviews**. HR can see only assigned candidates. Tabs combine with table filters; clear filters if an expected record is missing.

The default table highlights name, mobile, position, calling status, last call time, interest, earliest pending task and due time. Qualification, passout year, location, detailed round columns and assessment completion are available through optional columns. Existing saved column preferences may need to be adjusted using the column selector.

The next task is the earliest pending follow-up; the last call is determined by actual call time, with record ID resolving ties. A candidate can appear in multiple tabs. The work list does not automatically decide whom to reject or select.

## 5. Collect profile details in stages

Start with name, mobile and position. Entry date defaults to today. The collapsed **Profile details - complete later** section holds email, qualification, passout year, location, experience, salary, interest and resume details. Experience defaults to Unknown instead of assuming Fresher. Expand this section when creating or editing a candidate to add information as it becomes available.

Qualification, passout year and location now accept missing values. Existing values are preserved by migration. Email is still validated and is required by the email invitation action before sending. Completing optional profile information is an HR operating responsibility; interview scheduling does not introduce a new mandatory-profile gate.

## Deployment

Upload the changed application files and the new migration. Back up the production database and review/approve migrations before running:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

Deploy the earlier assessment-tracking migration too if it is not already installed; the combined call flow uses its unique call submission token. The staged-profile migration is `2026_10_29_130000_allow_staged_candidate_profiles.php`. Its rollback refuses to make fields mandatory while incomplete profiles exist, rather than inventing data.

No frontend build or new external service is required for these Filament actions. No real candidates were contacted during automated tests.
