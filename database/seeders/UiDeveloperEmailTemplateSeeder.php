<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class UiDeveloperEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $values = [
            'name' => 'UI Developer - Round 1 overview and assessment',
            'round_number' => 1,
            'purpose' => 'Invitation',
            'subject' => 'Spartan Infosoft Pvt. Ltd. | UI Developer (Fresher) - Round 1 assessment',
            'body' => file_get_contents(database_path('seeders/templates/ui-developer-round-1.md')),
            'body_format' => 'markdown',
            'is_active' => true,
        ];
        $template = EmailTemplate::firstOrCreate(['code' => 'ui_developer_round_1_overview'], $values);

        // Upgrade only our unchanged placeholder draft; preserve any administrator edits.
        if (! $template->wasRecentlyCreated
            && $template->subject === '{{company_name}} | UI Developer (Fresher) - Round 1 assessment'
            && hash('sha256', trim(str_replace("\r\n", "\n", $template->body))) === 'a7c654a3822c59b2dfce189ee00ce571eb9d0be1ac978f287f056379c2b3127a') {
            $template->update($values);
        }
    }
}
