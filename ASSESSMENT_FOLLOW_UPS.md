# Assessment follow-ups

## HR workflow

Use **Assessment follow-up** on a candidate row, the candidate View page, or a pending task in Follow Ups. Record the actual call time, candidate response, notes, and next follow-up date. One save records the call and updates assessment tracking and tasks together. This action records a telephone conversation; use existing editors for non-call updates.

| Response | Result |
| --- | --- |
| Completed | Mark Reported completed, record the reported completion time, close the source task, cancel remaining completion/support reminders, and create or update an Assessment result task. |
| Not completed yet | Mark Not completed and create or update an Assessment completion reminder. |
| Facing a problem | Require issue notes, mark Needs help, and create or update an Assessment support task. Use the existing WhatsApp action separately if needed. |
| Not reachable | Record Not Reachable, preserve assessment progress and schedule another attempt. |
| Not interested | Mark Not Interested. Cancel remaining assessment reminders only if HR selects the explicit cancellation toggle. |

When opened from a pending task, the source task is completed as the contact attempt is recorded. Completing a task is not proof that the exam is complete. Unrelated General follow-ups remain unchanged. Existing tasks default to General; HR can explicitly classify pending assessment tasks with the new Purpose field. No attempt is made to infer their purpose from old notes.

The next date must be in the future. A repeated submission of the same form does not create a second call. The operation is transactional: a failed validation saves nothing. HR ownership and existing workflow permissions still apply.

## Assessment completion is not a result

Reported completed means the candidate said they submitted the exam. It does not change Round 1 to Passed, supply a score, or unlock Round 2. Verify the result through the existing **Update round** action. Recording Passed/Failed closes pending result-verification tasks and cancels remaining completion/support reminders. Cancelling Round 1 cancels its assessment tasks.

The Candidates list now includes assessment completion and an optional completion-reported timestamp column, plus these filters:

- Assessment pending: interested, active candidates whose Round 1 is pending/scheduled/on hold and completion has not been reported.
- Completed - awaiting result: reported completion with Round 1 still pending/scheduled/on hold, excluding withdrawn/rejected candidates.
- Overdue assessment follow-ups: pending assessment-related tasks with a past due date.

An already Passed/Failed/Cancelled Round 1 cannot be changed through the call action. Conflicting information after completion was reported should be investigated, not silently reset. The completion timestamp is when completion was reported in the recorded call, not an independently verified exam submission timestamp.

## Deploy

Upload the changed application files and `database/migrations/2026_10_29_120000_add_assessment_follow_up_tracking.php`. Back up the database and approve the additive production migration before running:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan view:cache
```

This adds candidate completion tracking, follow-up purpose, and a unique call-submission token. It preserves existing records and interview results. No SMTP, cron, external assessment integration or automatic messages are required. Apply the migration before using the new screens.
