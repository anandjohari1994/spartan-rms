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
                        @continue($key === 'benefits')
                        <tr>
                            <td class="px-4 py-2">{{ $label }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown[$key . '_monthly'], 2) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number_format($breakdown[$key . '_annual'], 2) }}</td>
                        </tr>
                    @endforeach
                    @foreach (['gross' => 'Gross Salary', 'professional_tax' => 'Professional Tax', 'net' => 'Estimated Net Salary', 'benefits' => $components['benefits'], 'ctc' => 'Cost to Company - CTC'] as $key => $label)
                        <tr @class(['font-semibold bg-gray-50 dark:bg-white/5' => in_array($key, ['gross', 'net']), 'font-bold bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $key === 'ctc'])>
                            <td class="px-4 py-3">{{ $label }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($breakdown[$key . '_monthly'], 2) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($breakdown[$key . '_annual'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Basic: {{ $breakdown['salary_policy']['basic_percent_of_gross'] }}% of gross.
            HRA: {{ $breakdown['salary_policy']['hra_percent_of_basic'] }}% of basic.
            Employer PF: {{ $breakdown['salary_policy']['employer_pf_percent'] }}% of basic, capped at INR {{ number_format($breakdown['salary_policy']['pf_monthly_wage_ceiling'] * $breakdown['salary_policy']['employer_pf_percent'] / 100, 2) }}/month.
            @foreach (($breakdown['salary_policy']['allowance_percent_of_gross'] ?? []) as $key => $percent)
                {{ $components[$key] }}: {{ $percent }}% of gross.
            @endforeach
            The remaining gross salary is special allowance.
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Annual amounts define the package. Monthly Professional Tax and Estimated Net Salary represent a regular month; February may differ. Estimated Net Salary is gross salary less Professional Tax; employee PF is not deducted. Employer PF remains included in CTC. TDS and other applicable deductions are excluded; actual take-home pay may differ.</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Maharashtra Professional Tax at this gross salary: INR {{ $breakdown['professional_tax_regular_month'] }} per month, except INR {{ $breakdown['professional_tax_february'] }} in February. Estimated Net Salary in February: INR {{ number_format($breakdown['net_february'], 2) }}. Annual estimates include February and assume a full year at unchanged gross salary.</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">Enter a valid annual package and select a Professional Tax category to see the automatic monthly and annual breakup.</p>
    @endif
</div>
