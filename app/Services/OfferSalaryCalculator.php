<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OfferSalaryCalculator
{
    public const ANNUAL_CTC_RULES = 'required|numeric|min:1|max:999999999.99|decimal:0,2';

    public const PROFESSIONAL_TAX_CATEGORIES = ['male' => 'Maharashtra - Men', 'female' => 'Maharashtra - Women', 'exempt' => 'Maharashtra - Statutory exemption (verified by HR)'];

    public function calculate(mixed $annualCtc, mixed $taxCategory = null): array
    {
        Validator::make(['annual_package' => $annualCtc, 'professional_tax_category' => $taxCategory], [
            'annual_package' => self::ANNUAL_CTC_RULES,
            'professional_tax_category' => ['required', Rule::in(array_keys(self::PROFESSIONAL_TAX_CATEGORIES))],
        ])->validate();
        $policy = config('offer_letters');
        unset($policy['defaults']);
        $basicRate = $policy['basic_percent_of_gross'] / 100;
        $hraRate = $policy['hra_percent_of_basic'] / 100;
        $pfRate = $policy['employer_pf_percent'] / 100;
        $employeePfRate = $policy['employee_pf_percent'] / 100;
        $allowanceRates = array_map(fn ($percent) => $percent / 100, $policy['allowance_percent_of_gross']);
        if ($basicRate <= 0 || $basicRate * (1 + $hraRate) + array_sum($allowanceRates) > 1 || min($allowanceRates) < 0 || $hraRate < 0 || $pfRate < 0 || $employeePfRate < 0 || $employeePfRate > 1 || $policy['pf_monthly_wage_ceiling'] < 0) {
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
        // Employee PF is excluded from this offer estimate at the company's request.
        $annual['employee_pf'] = $monthly['employee_pf'] = 0;
        $tax = $this->maharashtraTax($monthly['gross'], $taxCategory);
        $annual['professional_tax'] = $tax['annual'];
        $monthly['professional_tax'] = $tax['regular'];
        $annual['net'] = $annual['gross'] - $annual['professional_tax'];
        $monthly['net'] = $monthly['gross'] - $monthly['professional_tax'];
        $result = [
            'annual_package' => $this->decimal($ctc), 'salary_policy' => $policy, 'salary_calculation_version' => 5,
            'professional_tax_category' => $taxCategory,
            'professional_tax_regular_month' => $this->decimal($tax['regular']),
            'professional_tax_february' => $this->decimal($tax['february']),
            'net_february' => $this->decimal($monthly['gross'] - $tax['february']),
            'professional_tax_schedule' => 'Maharashtra Schedule I, entry 1 (effective 1 April 2023)',
        ];
        foreach ($annual as $key => $paise) {
            $result[$key.'_annual'] = $this->decimal($paise);
            $result[$key.'_monthly'] = $this->decimal($monthly[$key]);
        }

        return $result;
    }

    public function preview(mixed $annualCtc, mixed $taxCategory = null): ?array
    {
        if (Validator::make(['annual_package' => $annualCtc, 'professional_tax_category' => $taxCategory], [
            'annual_package' => self::ANNUAL_CTC_RULES,
            'professional_tax_category' => ['required', Rule::in(array_keys(self::PROFESSIONAL_TAX_CATEGORIES))],
        ])->fails()) {
            return null;
        }

        return $this->calculate($annualCtc, $taxCategory);
    }

    /** All inputs and results are in paise; assumes unchanged monthly gross for a full year.
     * Source: Maharashtra Profession Tax Act, Schedule I entry 1, as at 31 December 2025.
     * https://cdnbbsr.s3waas.gov.in/s36a4cbdaedcbda0fa8ddc7ea32073c475/uploads/2026/02/202602161497231504.pdf
     */
    public function maharashtraTax(int $monthlyGrossPaise, string $category): array
    {
        if (! array_key_exists($category, self::PROFESSIONAL_TAX_CATEGORIES) || $monthlyGrossPaise < 0) {
            throw new \InvalidArgumentException('Invalid Maharashtra Professional Tax inputs.');
        }
        if ($category === 'exempt' || ($category === 'female' && $monthlyGrossPaise <= 2500000) || $monthlyGrossPaise <= 750000) {
            return ['regular' => 0, 'february' => 0, 'annual' => 0];
        }
        if ($category === 'male' && $monthlyGrossPaise <= 1000000) {
            return ['regular' => 17500, 'february' => 17500, 'annual' => 210000];
        }

        return ['regular' => 20000, 'february' => 30000, 'annual' => 250000];
    }

    private function decimal(int $paise): string
    {
        return number_format($paise / 100, 2, '.', '');
    }
}
