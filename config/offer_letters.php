<?php

return [
    // Company allocation defaults, not a universal statutory salary structure.
    'basic_percent_of_gross' => 50,
    'hra_percent_of_basic' => 40,
    'employer_pf_percent' => 12,
    // Revised effective 17 September 2026. Employer contribution is capped at 12% of this ceiling.
    // https://www.pib.gov.in/PressReleasePage.aspx?PRID=2313790&lang=2&reg=48
    'pf_monthly_wage_ceiling' => 25000,
];
