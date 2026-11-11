<?php

namespace Tests\Unit;

use App\Services\OfferLetterPdf;
use App\Services\OfferSalaryCalculator;
use Tests\TestCase;

class OfferSalaryCalculatorTest extends TestCase
{
    public function test_pf_cap_and_component_allocation(): void
    {
        $salary = app(OfferSalaryCalculator::class)->calculate('1200000');
        $this->assertSame('48500.00', $salary['basic_monthly']);
        $this->assertSame('19400.00', $salary['hra_monthly']);
        $this->assertSame('29100.00', $salary['special_monthly']);
        $this->assertSame('3000.00', $salary['benefits_monthly']);
        $this->assertSame('97000.00', $salary['gross_monthly']);
        $this->assertSame('100000.00', $salary['ctc_monthly']);
        $this->assertSame('1200000.00', $salary['ctc_annual']);
        $lower = app(OfferSalaryCalculator::class)->calculate('120000');
        $this->assertLessThan(3000, (float) $lower['benefits_monthly']);
        $this->assertEqualsWithDelta((float) $lower['basic_annual'] * .12, (float) $lower['benefits_annual'], .01);
    }

    public function test_rounding_preserves_entered_ctc_and_both_column_totals(): void
    {
        foreach (['1.00', '100.00', '120000.00', '600000.00', '636000.00', '1200000.00', '500000.01', '999999999.99'] as $input) {
            $salary = app(OfferSalaryCalculator::class)->calculate($input);
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
            }
        }
    }

    public function test_invalid_input_clears_preview(): void
    {
        foreach ([null, '', 'abc', -1, 0, '1.001', 1000000000, []] as $value) {
            $this->assertNull(app(OfferSalaryCalculator::class)->preview($value));
        }
    }
}
