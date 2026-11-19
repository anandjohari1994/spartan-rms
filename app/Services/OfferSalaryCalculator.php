<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class OfferSalaryCalculator
{
    public const ANNUAL_CTC_RULES = 'required|numeric|min:1|max:999999999.99|decimal:0,2';

    public function calculate(mixed $annualCtc): array
    {
        Validator::make(['annual_package' => $annualCtc], ['annual_package' => self::ANNUAL_CTC_RULES])->validate();
        $policy = config('offer_letters');
        $basicRate = $policy['basic_percent_of_gross'] / 100;
        $hraRate = $policy['hra_percent_of_basic'] / 100;
        $pfRate = $policy['employer_pf_percent'] / 100;
        $allowanceRates = array_map(fn ($percent) => $percent / 100, $policy['allowance_percent_of_gross']);
        if ($basicRate <= 0 || $basicRate * (1 + $hraRate) + array_sum($allowanceRates) > 1 || min($allowanceRates) < 0 || $hraRate < 0 || $pfRate < 0 || $policy['pf_monthly_wage_ceiling'] < 0) {
            throw new \LogicException('Invalid offer letter salary allocation configuration.');
        }
        // Calculate in annual paise so the CTC never changes because of monthly rounding.
        $ctc = (int) round((float) $annualCtc * 100);
        $pf = min(
            (int) round($ctc * $basicRate * $pfRate / (1 + $basicRate * $pfRate)),
            (int) round($policy['pf_monthly_wage_ceiling'] * 12 * 100 * $pfRate),
        );
        $gross = $ctc - $pf;
        $basic = (int) round($gross * $basicRate);
        $hra = (int) round($basic * $hraRate);
        $allowances = array_map(fn ($rate) => (int) round($gross * $rate), $allowanceRates);
        $annual = ['basic' => $basic, 'hra' => $hra, ...$allowances, 'special' => $gross - $basic - $hra - array_sum($allowances), 'benefits' => $pf, 'gross' => $gross, 'ctc' => $ctc];
        $monthly = array_map(fn (int $paise) => (int) round($paise / 12), $annual);
        // Allocate rounding residuals to special allowance, keeping both columns additive.
        $monthly['gross'] = $monthly['ctc'] - $monthly['benefits'];
        $monthly['special'] = $monthly['gross'] - $monthly['basic'] - $monthly['hra'] - array_sum(array_intersect_key($monthly, $allowances));
        $result = ['annual_package' => $this->decimal($ctc), 'salary_policy' => $policy, 'salary_calculation_version' => 2];
        foreach ($annual as $key => $paise) {
            $result[$key.'_annual'] = $this->decimal($paise);
            $result[$key.'_monthly'] = $this->decimal($monthly[$key]);
        }

        return $result;
    }

    public function preview(mixed $annualCtc): ?array
    {
        if (Validator::make(['annual_package' => $annualCtc], ['annual_package' => self::ANNUAL_CTC_RULES])->fails()) {
            return null;
        }

        return $this->calculate($annualCtc);
    }

    private function decimal(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}
