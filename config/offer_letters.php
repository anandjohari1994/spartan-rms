<?php

return [
    'defaults' => [
        'department' => 'Software Development',
        'work_location' => 'Remote (Work From Home)',
        'employment_type' => 'Full-Time',
        'probation_months' => 3,
        'annual_package' => '300000.00',
        'salary_payment_day' => 1,
        'signatory_name' => 'Shaurya Johari',
        'signatory_designation' => 'HR Manager',
        'optional_clause' => 'include',
        'notice_probation' => '1 month',
        'notice_confirmed' => '1 month',
        'working_hours' => '9 hours per day',
        'working_days' => '5 days per week (Monday to Friday)',
        'leave_policy' => 'You would be entitled for total 18 Leaves in a year (12 earned leaves + 6 Sick Leaves), You would be credited with 1.5 days of leave upon providing full month of your service. Leaves cannot be encashed and all unutilized leaves will lapse at the end of the calendar year.',
    ],
    // Company allocation defaults, not a universal statutory salary structure.
    'basic_percent_of_gross' => 50,
    'hra_percent_of_basic' => 40,
    'allowance_percent_of_gross' => [
        'lta' => 5,
        'medical' => 2.5,
        'conveyance' => 2.5,
        'other' => 5,
    ],
    'employer_pf_percent' => 12,
    'employee_pf_percent' => 12,
    // Revised effective 17 September 2026. Employer contribution is capped at 12% of this ceiling.
    // https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313790&lang=2&reg=48
    'pf_monthly_wage_ceiling' => 25000,
];
