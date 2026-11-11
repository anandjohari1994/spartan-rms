<?php

namespace App\Services;

use App\Support\OfferPdfDocument;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class OfferLetterPdf
{
    public const SALARY_COMPONENTS = ['basic' => 'Basic Salary', 'hra' => 'House Rent Allowance (HRA)', 'lta' => 'Leave Travel Allowance (LTA)', 'medical' => 'Medical Allowance', 'conveyance' => 'Conveyance Allowance', 'special' => 'Special Allowance', 'other' => 'Other Component', 'benefits' => "Employer's PF / Other Benefits"];

    public function values(array $details): array
    {
        $values = $details;
        foreach (['letter_date', 'joining_date', 'commencement_date', 'document_deadline', 'valid_until', 'acceptance_deadline'] as $key) {
            $values[$key] = Carbon::parse($details[$key])->format('d F Y');
        }
        $values['signature_date'] = Carbon::parse($details['letter_date'])->format('d/m/Y');
        $day = (int) $details['salary_payment_day'];
        $values['salary_payment_day'] = $day.(in_array($day % 100, [11, 12, 13]) ? 'th' : match ($day % 10) {
            1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th'
        });
        if (isset($details['salary_calculation_version'])) {
            foreach ([...array_keys(self::SALARY_COMPONENTS), 'gross', 'ctc'] as $key) {
                foreach (['monthly', 'annual'] as $period) {
                    $values[$key.'_'.$period] = number_format((float) $details[$key.'_'.$period], 2);
                }
            }
            $values['monthly_gross'] = $values['gross_monthly'];
            $values['annual_ctc'] = number_format((float) $details['annual_package'], 2);

            return $values;
        }
        // Retain rendering compatibility with the original manually entered drafts.
        $gross = 0;
        foreach (self::SALARY_COMPONENTS as $key => $label) {
            $paise = (int) round((float) $details[$key.'_monthly'] * 100);
            if ($key !== 'benefits') {
                $gross += $paise;
            }
            $values[$key.'_monthly'] = number_format($paise / 100, 2);
            $values[$key.'_annual'] = number_format($paise * 12 / 100, 2);
        }
        $ctc = $gross + (int) round((float) $details['benefits_monthly'] * 100);
        $values['gross_monthly'] = $values['monthly_gross'] = number_format($gross / 100, 2);
        $values['gross_annual'] = number_format($gross * 12 / 100, 2);
        $values['ctc_monthly'] = number_format($ctc / 100, 2);
        $values['annual_ctc'] = number_format($ctc * 12 / 100, 2);

        return $values;
    }

    public function generate(array $details): string
    {
        $values = $this->values($details);
        $pdf = new OfferPdfDocument;
        $pdf->AddFont('Carlito', '', 'Carlito-Regular.ttf', true);
        $pdf->AddFont('Carlito', 'B', 'Carlito-Bold.ttf', true);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Conditional Offer of Employment - '.$details['candidate_name'], true);
        $pdf->SetAuthor('Spartan Infosoft Pvt. Ltd.', true);
        $pdf->setSourceFile(resource_path('offer-letters/conditional-offer.pdf'));
        $layout = json_decode(file_get_contents(resource_path('offer-letters/layout.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($layout as $index => $page) {
            $pdf->AddPage('P', [$page['width'], $page['height']]);
            $pdf->useTemplate($pdf->importPage($index + 1));
            foreach ($page['entries'] as $entry) {
                $runs = array_map(fn ($run) => [...$run, 'text' => isset($run['key']) ? (string) $values[$run['key']] : $run['text']], $entry['runs']);
                $size = $entry['size'];
                $width = function () use ($pdf, $runs, &$size): float {
                    $total = 0;
                    foreach ($runs as $run) {
                        $pdf->SetFont('Carlito', $run['bold'] ? 'B' : '', $size);
                        $total += $pdf->GetStringWidth($run['text']);
                    }

                    return $total;
                };
                $available = $entry['right'] - $entry['x'];
                $size = min($size, $size * $available / max(1, $width()));
                if ($size < 7.5) {
                    $key = collect($runs)->first(fn ($run) => isset($run['key']))['key'];
                    throw ValidationException::withMessages([$key => 'This text is too long for the reference letter layout. Please shorten it.']);
                }
                $pdf->SetFillColor(...($entry['background'] ?? [255, 255, 255]));
                $pdf->Rect($entry['x'] - .15, $entry['top'] - .5, $available + .3, $entry['bottom'] - $entry['top'] + 1, 'F');
                $pdf->SetTextColor(...($entry['color'] ?? [0, 0, 0]));
                $x = ($entry['align'] ?? 'left') === 'right' ? $entry['right'] - $width() : $entry['x'];
                foreach ($runs as $run) {
                    $pdf->SetFont('Carlito', $run['bold'] ? 'B' : '', $size);
                    $pdf->Text($x, $entry['baseline'], $run['text']);
                    $x += $pdf->GetStringWidth($run['text']);
                }
            }
            if ($index === 1) {
                // Resolve the reference's editorial choice without changing the surrounding clauses.
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Rect(71.9, 587.4, 452.2, 40.6, 'F');
                if ($details['optional_clause'] === 'include') {
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetFont('Carlito', '', 10.5);
                    $pdf->SetXY(72.1, 586.4);
                    $pdf->MultiCell(451.7, 14.4, '5.6 If you are not selected by the client, the Company may, at its sole discretion, consider you for other suitable opportunities, failing which your employment may be reviewed or ended in accordance with the Employment Agreement and applicable law.');
                }
            }
        }

        return $pdf->Output('S');
    }
}
