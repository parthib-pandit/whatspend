<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Transactions</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white p-6 shadow sm:rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">This Month's Spending by Category</h3>
                        @if ($categoryBreakdown->isEmpty())
                            <p class="text-gray-400 text-sm">No spending logged this month yet.</p>
                        @else
                            <div class="relative h-56">
                                <canvas id="categoryChart"></canvas>
                            </div>
                        @endif
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">Monthly Spending Trend</h3>
                        @if ($monthlyTrend->isEmpty())
                            <p class="text-gray-400 text-sm">Not enough data yet.</p>
                        @else
                            <div class="relative h-56">
                                <canvas id="trendChart"></canvas>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 shadow sm:rounded-lg">

                @if (session('status'))
                    <div class="mb-4 text-sm text-green-600">{{ session('status') }}</div>
                @endif

                <div class="flex justify-between items-center mb-4">
                    <form method="GET" class="flex gap-2 flex-wrap items-end">
                        <div>
                            <label class="block text-xs text-gray-500">Type</label>
                            <select name="type" class="rounded-md border-gray-300 text-sm">
                                <option value="">All</option>
                                <option value="debit" @selected(request('type') === 'debit')>Debit</option>
                                <option value="credit" @selected(request('type') === 'credit')>Credit</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">Category</label>
                            <select name="category" class="rounded-md border-gray-300 text-sm">
                                <option value="">All</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->value }}" @selected(request('category') === $cat->value)>{{ $cat->value }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">From</label>
                            <input type="date" name="from" value="{{ request('from') }}" class="rounded-md border-gray-300 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500">To</label>
                            <input type="date" name="to" value="{{ request('to') }}" class="rounded-md border-gray-300 text-sm">
                        </div>
                        <x-primary-button>Filter</x-primary-button>
                        <a href="{{ route('transactions.index') }}" class="text-sm text-gray-500 underline self-center">Reset</a>
                    </form>

                    <a href="{{ route('transactions.create') }}">
                        <x-primary-button>+ Add Transaction</x-primary-button>
                    </a>
                </div>

                @if ($transactions->isEmpty())
                    <p class="text-gray-500">No transactions yet.</p>
                @else
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="py-2">Date</th>
                                <th class="py-2">Type</th>
                                <th class="py-2">Amount</th>
                                <th class="py-2">Category</th>
                                <th class="py-2">Note</th>
                                <th class="py-2">Source</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $t)
                                <tr class="border-b">
                                    <td class="py-2">{{ $t->transaction_date->format('d M Y') }}</td>
                                    <td class="py-2 capitalize">{{ $t->type }}</td>
                                    <td class="py-2 {{ $t->type === 'debit' ? 'text-red-600' : 'text-green-600' }}">
                                        ₹{{ number_format($t->amount, 2) }}
                                    </td>
                                    <td class="py-2">{{ $t->category }}</td>
                                    <td class="py-2">{{ $t->note }}</td>
                                    <td class="py-2 capitalize">{{ $t->source }}</td>
                                    <td class="py-2">
                                        <a href="{{ route('transactions.edit', $t) }}" class="text-indigo-600 text-sm">Edit</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <div class="mt-4">
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
            @if ($categoryBreakdown->isNotEmpty())
            new Chart(document.getElementById('categoryChart'), {
                type: 'pie',
                data: {
                    labels: {!! json_encode($categoryBreakdown->pluck('category')) !!},
                    datasets: [{
                        data: {!! json_encode($categoryBreakdown->pluck('total')) !!},
                        backgroundColor: ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316'],
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
            @endif

            @if ($monthlyTrend->isNotEmpty())
            new Chart(document.getElementById('trendChart'), {
                type: 'bar',
                data: {
                    labels: {!! json_encode($monthlyTrend->pluck('month')) !!},
                    datasets: [{
                        label: 'Total Debit (₹)',
                        data: {!! json_encode($monthlyTrend->pluck('total')) !!},
                        backgroundColor: '#6366f1',
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true } }
                }
            });
            @endif
        </script>
        @endpush
    @endif
</x-app-layout>