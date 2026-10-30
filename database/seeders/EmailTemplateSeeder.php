<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Support\Recruitment;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Recruitment::ROUNDS as $number => $name) {
            foreach (['Invitation', 'Cleared'] as $purpose) {
                $body = $purpose === 'Invitation'
                    ? "Hello,\n\nYou are invited to Round {{round_number}} - {{round_name}} with {{company_name}}.\n\nDate/time (including time zone): {{session_time}}\nAssessment or meeting link: {{link}}\nDeadline / confirmation by: {{deadline}}\n\nInstructions: {{instructions}}\n\nFor assistance: {{support_contact}}\n\nRegards,\n{{company_name}} Recruitment Team"
                    : "Hello,\n\nWe are pleased to confirm that you have cleared Round {{round_number}} - {{round_name}} with {{company_name}}.\n\nNext steps: {{instructions}}\n\nFor assistance: {{support_contact}}\n\n".($number === 3 ? "Any employment offer will be issued separately after approval. This email is not an offer letter.\n\n" : '')."Regards,\n{{company_name}} Recruitment Team";
                EmailTemplate::firstOrCreate(['code' => 'round_'.$number.'_'.strtolower($purpose)], [
                    'name' => "Round {$number} - {$purpose}", 'round_number' => $number, 'purpose' => $purpose,
                    'subject' => '{{company_name}} | Round {{round_number}} '.($purpose === 'Invitation' ? 'invitation' : 'cleared'),
                    'body' => $body, 'is_active' => true,
                ]);
            }
        }
    }
}
