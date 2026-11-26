<?php

namespace Tests\Unit;

use App\Services\OfferLetterPdf;
use App\Services\OfferSalaryCalculator;
use Tests\TestCase;

class OfferSalaryCalculatorTest extends TestCase
{
    public function test_pf_cap_and_component_allocation(): void
    {
        $salary = app(OfferSalaryCalculator::class)->calculate('1200000', 'male');
        $this->assertSame('48500.00', $salary['basic_monthly']);
        $this->assertSame('19400.00', $salary['hra_monthly']);
        $this->assertSame('14550.00', $salary['special_monthly']);
        $this->assertSame('4850.00', $salary['lta_monthly']);
        $this->assertSame('2425.00', $salary['medical_monthly']);
        $this->assertSame('2425.00', $salary['conveyance_monthly']);
        $this->assertSame('4850.00', $salary['other_monthly']);
        $this->assertSame('58200.00', $salary['lta_annual']);
        $this->assertSame('29100.00', $salary['medical_annual']);
        $this->assertSame('29100.00', $salary['conveyance_annual']);
        $this->assertSame('58200.00', $salary['other_annual']);
        $this->assertSame('3000.00', $salary['benefits_monthly']);
        $this->assertSame('97000.00', $salary['gross_monthly']);
        $this->assertSame('100000.00', $salary['ctc_monthly']);
        $this->assertSame('1200000.00', $salary['ctc_annual']);
        $this->assertSame('0.00', $salary['employee_pf_monthly']);
        $this->assertSame('96800.00', $salary['net_monthly']);
        $this->assertSame('1161500.00', $salary['net_annual']);
        $lower = app(OfferSalaryCalculator::class)->calculate('120000', 'male');
        $this->assertLessThan(3000, (float) $lower['benefits_monthly']);
        $this->assertEqualsWithDelta((float) $lower['basic_annual'] * .12, (float) $lower['benefits_annual'], .01);
    }

    public function test_rounding_preserves_entered_ctc_and_both_column_totals(): void
    {
        foreach (['1.00', '100.00', '120000.00', '600000.00', '636000.00', '1200000.00', '500000.01', '999999999.99'] as $input) {
            $salary = app(OfferSalaryCalculator::class)->calculate($input, 'male');
            $this->assertSame($input, $salary['annual_package']);
            $this->assertSame($input, $salary['ctc_annual']);
            foreach (['monthly', 'annual'] as $period) {
                $sum = 0;
                foreach (array_keys(OfferLetterPdf::SALARY_COMPONENTS) as $component) {
                    $amount = (int) round((float) $salary[$component.'_'.$period] * 100);
                    $this->assertGreaterThanOrEqual(0, $amount);
                    if ($component !== 'benefits') {
                        $sum += $amount;
                    }
                }
                $this->assertSame((int) round((float) $salary['gross_'.$period] * 100), $sum);
                $this->assertSame((int) round((float) $salary['ctc_'.$period] * 100), $sum + (int) round((float) $salary['benefits_'.$period] * 100));
                $this->assertSame((int) round((float) $salary['net_'.$period] * 100), $sum - (int) round((float) $salary['professional_tax_'.$period] * 100));
            }
        }
    }

    public function test_invalid_input_clears_preview(): void
    {
        foreach ([null, '', 'abc', -1, 0, '1.001', 1000000000, []] as $value) {
            $this->assertNull(app(OfferSalaryCalculator::class)->preview($value, 'male'));
        }
        foreach ([null, '', 'unknown'] as $category) {
            $this->assertNull(app(OfferSalaryCalculator::class)->preview('300000', $category));
        }
    }

    public function test_maharashtra_slab_boundaries_and_exemptions_in_paise(): void
    {
        $calculator = app(OfferSalaryCalculator::class);
        foreach ([
            ['male', 750000, 0, 0, 0], ['male', 750001, 17500, 17500, 210000],
            ['male', 1000000, 17500, 17500, 210000], ['male', 1000001, 20000, 30000, 250000],
            ['female', 750001, 0, 0, 0], ['female', 2500000, 0, 0, 0],
            ['female', 2500001, 20000, 30000, 250000], ['exempt', 10000000, 0, 0, 0],
        ] as [$category, $gross, $regular, $february, $annual]) {
            $this->assertSame(compact('regular', 'february', 'annual'), $calculator->maharashtraTax($gross, $category));
        }
        $male = $calculator->calculate('300000', 'male');
        $female = $calculator->calculate('300000', 'female');
        $this->assertSame('2500.00', $male['professional_tax_annual']);
        $this->assertSame('200.00', $male['professional_tax_monthly']);
        $this->assertSame('23384.91', $male['net_monthly']);
        $this->assertSame('23284.91', $male['net_february']);
        $this->assertSame('280518.87', $male['net_annual']);
        $this->assertSame(250000, (int) round(100 * (11 * (float) $male['professional_tax_monthly'] + (float) $male['professional_tax_february'])));
        $this->assertSame('0.00', $male['employee_pf_monthly']);
        $this->assertSame('0.00', $female['professional_tax_annual']);
        $this->assertSame($female['gross_monthly'], $female['net_monthly']);
        $this->assertSame($male['ctc_annual'], $female['ctc_annual']);
    }
}
