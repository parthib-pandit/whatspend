<x-app-layout>
    <x-slot name="header">Ledger</x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="text-sm text-[#5B9279] font-num">{{ session('status') }}</div>
            @endif

            {{-- KPI row: $monthSpent, $monthIncome, $monthNet, $netChangePct passed from controller --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-4">
                    <div class="text-xs text-[#B9AF98] mb-1.5">Spent this month</div>
                    <div class="font-num text-2xl text-[#C1443C]">₹{{ number_format($monthSpent ?? 0, 0) }}</div>
                </div>
                <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-4">
                    <div class="text-xs text-[#B9AF98] mb-1.5">Income this month</div>
                    <div class="font-num text-2xl text-[#5B9279]">₹{{ number_format($monthIncome ?? 0, 0) }}</div>
                </div>
                <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-4">
                    <div class="text-xs text-[#B9AF98] mb-1.5">Net</div>
                    <div class="font-num text-2xl text-[#EDE6D6]">₹{{ number_format($monthNet ?? 0, 0) }}</div>
                    @if (isset($netChangePct))
                        <div class="text-xs mt-1 {{ $netChangePct >= 0 ? 'text-[#5B9279]' : 'text-[#C1443C]' }}">
                            {{ $netChangePct >= 0 ? '↑' : '↓' }} {{ number_format(abs($netChangePct), 0) }}% vs last month
                        </div>
                    @endif
                </div>
            </div>

            {{-- Charts --}}
            <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div>
                        <h3 class="text-xs text-[#B9AF98] mb-3">This month's spending by category</h3>
                        @if ($categoryBreakdown->isEmpty())
                            <p class="text-[#6b6355] text-sm">No spending logged this month yet.</p>
                        @else
                            <div class="relative h-48">
                                <canvas id="categoryChart"></canvas>
                            </div>
                            <div id="categoryLegend" class="flex flex-wrap gap-x-4 gap-y-1.5 mt-3 text-xs text-[#B9AF98]"></div>
                        @endif
                    </div>
                    <div>
                        <h3 class="text-xs text-[#B9AF98] mb-3">Monthly spending trend</h3>
                        @if ($monthlyTrend->isEmpty())
                            <p class="text-[#6b6355] text-sm">Not enough data yet.</p>
                        @else
                            <div class="relative h-48">
                                <canvas id="trendChart"></canvas>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Recurring expenses --}}
            @if (!empty($recurringPatterns))
                <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-6">
                    <h3 class="text-xs text-[#B9AF98] mb-3">🔁 Likely recurring expenses</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach ($recurringPatterns as $pattern)
                            <div class="border border-[#332C1F] rounded-md p-3">
                                <div class="flex justify-between items-baseline">
                                    <span class="font-sans text-sm text-[#EDE6D6]">{{ $pattern['category'] }}</span>
                                    <span class="font-num text-sm text-[#C9A227]">₹{{ number_format($pattern['average_amount'], 2) }}</span>
                                </div>
                                <div class="text-xs text-[#6b6355] mt-1">
                                    {{ $pattern['occurrences'] }}× · every ~{{ round($pattern['average_interval_days']) }} days · last {{ \Carbon\Carbon::parse($pattern['last_date'])->format('d M') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Ledger table --}}
            <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-6">

                <div class="flex justify-between items-end mb-5 flex-wrap gap-3">
                    <form method="GET" class="flex gap-3 flex-wrap items-end">
                        <div>
                            <label class="block text-xs text-[#6b6355] mb-1">Type</label>
                            <select name="type" class="rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] text-sm focus:border-[#C9A227] focus:ring-[#C9A227]">
                                <option value="">All</option>
                                <option value="debit" @selected(request('type') === 'debit')>Debit</option>
                                <option value="credit" @selected(request('type') === 'credit')>Credit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-[#6b6355] mb-1">Category</label>
                            <select name="category" class="rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] text-sm focus:border-[#C9A227] focus:ring-[#C9A227]">
                                <option value="">All</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->value }}" @selected(request('category') === $cat->value)>{{ $cat->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-[#6b6355] mb-1">From</label>
                            <input type="date" name="from" value="{{ request('from') }}" class="rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] text-sm focus:border-[#C9A227] focus:ring-[#C9A227]">
                        </div>
                        <div>
                            <label class="block text-xs text-[#6b6355] mb-1">To</label>
                            <input type="date" name="to" value="{{ request('to') }}" class="rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] text-sm focus:border-[#C9A227] focus:ring-[#C9A227]">
                        </div>
                        <button type="submit" class="rounded-md bg-[#C9A227] text-[#15120E] text-sm font-medium px-4 py-2 hover:bg-[#dab438] transition">Filter</button>
                        <a href="{{ route('transactions.index') }}" class="text-sm text-[#6b6355] underline self-center">Reset</a>
                    </form>

                    <a href="{{ route('transactions.create') }}" class="rounded-md border border-[#C9A227] text-[#C9A227] text-sm font-medium px-4 py-2 hover:bg-[#C9A227] hover:text-[#15120E] transition">
                        + Add transaction
                    </a>
                </div>

                @if ($transactions->isEmpty())
                    <p class="text-[#6b6355] text-sm">No transactions yet.</p>
                @else
                    <table class="w-full text-left text-sm font-num">
                        <thead>
                            <tr class="border-b border-[#332C1F] text-[#6b6355] text-xs font-sans">
                                <th class="py-2 font-normal w-10">#</th>
                                <th class="py-2 font-normal">Date</th>
                                <th class="py-2 font-normal font-sans">Category</th>
                                <th class="py-2 font-normal font-sans">Note</th>
                                <th class="py-2 font-normal text-right">Amount</th>
                                <th class="py-2 font-normal font-sans">Source</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $i => $t)
                                <tr class="border-b border-[#241f16]">
                                    <td class="py-2.5 text-[#6b6355]">{{ str_pad($transactions->firstItem() + $i, 3, '0', STR_PAD_LEFT) }}</td>
                                    <td class="py-2.5 text-[#B9AF98]">{{ $t->transaction_date->format('d M') }}</td>
                                    <td class="py-2.5 font-sans">{{ $t->category }}</td>
                                    <td class="py-2.5 font-sans text-[#B9AF98]">{{ $t->note }}</td>
                                    <td class="py-2.5 text-right {{ $t->type === 'debit' ? 'text-[#C1443C]' : 'text-[#5B9279]' }}">
                                        {{ $t->type === 'debit' ? '-' : '+' }}₹{{ number_format($t->amount, 2) }}
                                    </td>
                                    <td class="py-2.5 font-sans capitalize text-[#6b6355] text-xs">{{ $t->source }}</td>
                                    <td class="py-2.5 font-sans">
                                        <a href="{{ route('transactions.edit', $t) }}" class="text-[#C9A227] text-xs hover:underline">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="mt-4 text-[#B9AF98] [&_a]:text-[#C9A227] [&_span]:text-[#6b6355]">
                        {{ $transactions->links() }}
                    </div>
                @endif

            </div>
        </div>
    </div>

    @if ($categoryBreakdown->isNotEmpty() || $monthlyTrend->isNotEmpty())
        @push('scripts')
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
        <script>
            const ledgerPalette = ['#C9A227', '#8C7018', '#5B9279', '#B9AF98', '#C1443C', '#6b6355', '#dab438', '#3f6b52'];

            @if ($categoryBreakdown->isNotEmpty())
            const catLabels = {!! json_encode($categoryBreakdown->pluck('category')) !!};
            const catData = {!! json_encode($categoryBreakdown->pluck('total')) !!};
            const catColors = catLabels.map((_, i) => ledgerPalette[i % ledgerPalette.length]);

            new Chart(document.getElementById('categoryChart'), {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{ data: catData, backgroundColor: catColors, borderColor: '#1D1911', borderWidth: 2 }]
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '62%',
                    onClick: (evt, elements) => {
                        if (!elements.length) return;
                        const label = catLabels[elements[0].index];
                        window.location.href = '{{ route('transactions.index') }}?category=' + encodeURIComponent(label);
                    },
                    onHover: (evt, elements) => {
                        evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
                    },
                    plugins: { legend: { display: false } }
                }
            });

            document.getElementById('categoryLegend').innerHTML = catLabels.map((label, i) =>
                `<a href="{{ route('transactions.index') }}?category=${encodeURIComponent(label)}" class="flex items-center gap-1.5 hover:text-[#EDE6D6] transition"><span style="width:8px;height:8px;border-radius:2px;background:${catColors[i]};display:inline-block;"></span>${label}</a>`
            ).join('');
            @endif

            @if ($monthlyTrend->isNotEmpty())
            new Chart(document.getElementById('trendChart'), {
                type: 'bar',
                data: {
                    labels: {!! json_encode($monthlyTrend->pluck('month')) !!},
                    datasets: [{
                        data: {!! json_encode($monthlyTrend->pluck('total')) !!},
                        backgroundColor: '#C9A227',
                        borderRadius: 3,
                        maxBarThickness: 26,
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#B9AF98' }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: '#B9AF98' }, grid: { color: '#241f16' } }
                    }
                }
            });
            @endif
        </script>
        @endpush
    @endif
</x-app-layout>