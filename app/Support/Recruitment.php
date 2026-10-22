<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Recruitment
{
    public const CALLS = ['Not Called', 'Connected', 'Not Reachable', 'Busy', 'Switched Off', 'Wrong Number', 'Callback Requested'];

    public const STATUSES = ['New', 'In Progress', 'On Hold', 'Selected', 'Rejected', 'Withdrawn', 'Joined'];

    public const INTERVIEWS = ['Not Started', 'Pending', 'Scheduled', 'Passed', 'Failed', 'On Hold', 'Cancelled'];

    public const ROUNDS = [1 => 'Online Assessment', 2 => 'Internal Technical Interview', 3 => 'Client Interview'];

    public static function options(array $values): array
    {
        return array_combine($values, $values);
    }

    public static function mobile(string $value): string
    {
        $digits = preg_replace('/[\\s().-]/', '', trim($value));
        $digits = preg_replace('/^(?:\\+91|0091|91(?=\\d{10}$)|0(?=\\d{10}$))/', '', $digits);
        if (! preg_match('/^[6-9]\\d{9}$/', $digits)) {
            throw ValidationException::withMessages(['mobile' => 'Enter a valid 10-digit Indian mobile number.']);
        }

        return '+91'.$digits;
    }
}
