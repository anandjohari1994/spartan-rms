<div aria-live="polite" class="space-y-3">
    @if ($breakdown)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm" aria-label="Automatic salary breakup">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left">Salary component</th>
                        <th class="px-4 py-3 text-right">Monthly (INR)</th>
                        <th class="px-4 py-3 text-right">Annual (INR)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($components as $key => $label)
                        @if ($key === 'benefits')
                            <tr class="bg-gray-50 font-semibold dark:bg-white/5">
                                <td class="px-4 py-2">Gross salary</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown['gross_monthly'], 2) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown['gross_annual'], 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="px-4 py-2">{{ $label }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown[$key . '_monthly'], 2) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown[$key . '_annual'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="bg-primary-50 font-bold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                        <td class="px-4 py-3">Cost to Company (CTC)</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($breakdown['ctc_monthly'], 2) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($breakdown['ctc_annual'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Basic: {{ $breakdown['salary_policy']['basic_percent_of_gross'] }}% of gross.
            HRA: {{ $breakdown['salary_policy']['hra_percent_of_basic'] }}% of basic.
            Employer PF: {{ $breakdown['salary_policy']['employer_pf_percent'] }}% of basic, capped at INR {{ number_format($breakdown['salary_policy']['pf_monthly_wage_ceiling'] * $breakdown['salary_policy']['employer_pf_percent'] / 100, 2) }}/month.
            The remaining gross salary is special allowance; other allowances are zero.
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Annual amounts define the package. Monthly figures are rounded to two decimals, with rounding adjustments in special allowance. Employee deductions are not subtracted from CTC; this is not take-home pay.</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Enter a valid annual package to see the automatic monthly and annual breakup.</p>
    @endif
</div>
