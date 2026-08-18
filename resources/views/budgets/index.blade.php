<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-display text-xl text-[#EDE6D6]">
                Budgets
            </h2>
            <p class="text-xs text-[#EDE6D6]/50 mt-1">
                Set spending limits and stay on track each month.
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-8">

            {{-- Status --}}
            @if (session('status'))
                <div class="rounded-lg border border-[#C9A227]/30 bg-[#1D1911] px-4 py-3 text-sm text-[#EDE6D6]">
                    {{ session('status') }}
                </div>
            @endif


            {{-- Existing budgets --}}
            <section>

                <div class="flex items-end justify-between mb-4">
                    <div>
                        <h3 class="font-display text-lg text-[#EDE6D6]">
                            Your budgets
                        </h3>

                        <p class="text-xs text-[#EDE6D6]/45 mt-1">
                            Monitor how much you've spent against each limit.
                        </p>
                    </div>

                    <span class="text-xs text-[#EDE6D6]/40">
                        {{ count($budgetsWithSpend) }}
                        {{ count($budgetsWithSpend) === 1 ? 'budget' : 'budgets' }}
                    </span>
                </div>


                <div class="space-y-4">

                    @forelse ($budgetsWithSpend as $row)

                        @php
                            $budget = $row['budget'];
                            $percent = $row['percent'];

                            if ($row['over']) {
                                $statusText = 'Over budget';
                                $statusClass = 'text-red-400 bg-red-400/10 border-red-400/20';
                                $barColor = 'bg-red-500';
                            } elseif ($row['near']) {
                                $statusText = 'Near limit';
                                $statusClass = 'text-[#C9A227] bg-[#C9A227]/10 border-[#C9A227]/20';
                                $barColor = 'bg-[#C9A227]';
                            } else {
                                $statusText = 'On track';
                                $statusClass = 'text-emerald-400 bg-emerald-400/10 border-emerald-400/20';
                                $barColor = 'bg-emerald-500';
                            }

                            $progressWidth = min($percent, 100);
                        @endphp


                        <div class="bg-[#1D1911] border border-[#C9A227]/20 rounded-xl overflow-hidden">

                            {{-- Header --}}
                            <div class="px-5 sm:px-6 py-5">

                                <div class="flex items-center justify-between gap-4">

                                    <div class="flex items-center gap-3 min-w-0">

                                        <div class="w-9 h-9 shrink-0 rounded-lg bg-[#C9A227]/10 border border-[#C9A227]/15 flex items-center justify-center text-[#C9A227]">
                                            ₹
                                        </div>

                                        <div class="min-w-0">
                                            <h4 class="text-sm font-medium text-[#EDE6D6] truncate">
                                                {{ $budget->category ?? 'Overall' }}
                                            </h4>

                                            <p class="text-[11px] text-[#EDE6D6]/40 mt-0.5">
                                                Monthly budget
                                            </p>
                                        </div>

                                    </div>


                                    {{-- Status --}}
                                    <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] {{ $statusClass }}">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                        {{ $statusText }}
                                    </span>

                                </div>


                                {{-- Spending summary --}}
                                <div class="mt-6 grid grid-cols-3 gap-4">

                                    <div>
                                        <p class="text-[11px] text-[#EDE6D6]/40">
                                            Spent
                                        </p>

                                        <p class="font-mono text-xl font-semibold text-[#EDE6D6] mt-1">
                                            ₹{{ number_format($row['spent'], 2) }}
                                        </p>
                                    </div>


                                    <div>
                                        <p class="text-[11px] text-[#EDE6D6]/40">
                                            Monthly limit
                                        </p>

                                        <p class="font-mono text-xl font-semibold text-[#EDE6D6] mt-1">
                                            ₹{{ number_format($budget->monthly_limit, 2) }}
                                        </p>
                                    </div>


                                    <div class="text-right">
                                        <p class="text-[11px] text-[#EDE6D6]/40">
                                            Used
                                        </p>

                                        <p class="font-mono text-xl font-semibold text-[#EDE6D6] mt-1">
                                            {{ number_format($percent, 0) }}%
                                        </p>
                                    </div>

                                </div>


                                {{-- Progress --}}
                                <div class="mt-5">

                                    <div class="relative h-2 rounded-full bg-[#15120E] overflow-hidden">

                                        {{-- Alert threshold --}}
                                        <div
                                            class="absolute top-0 bottom-0 w-px bg-[#EDE6D6]/70 z-10"
                                            style="left: {{ min($budget->alert_threshold_percent, 100) }}%"
                                        ></div>

                                        {{-- Spending --}}
                                        <div
                                            class="h-full rounded-full {{ $barColor }}"
                                            style="width: {{ $progressWidth }}%"
                                        ></div>

                                    </div>

                                    <div class="flex justify-between items-center mt-2 text-[10px] text-[#EDE6D6]/35">
                                        <span>₹0</span>

                                        <span>
                                            Alert at {{ $budget->alert_threshold_percent }}%
                                        </span>

                                        <span>
                                            ₹{{ number_format($budget->monthly_limit, 0) }}
                                        </span>
                                    </div>

                                </div>

                            </div>


                            {{-- Edit --}}
                            <div class="border-t border-[#C9A227]/10 px-5 sm:px-6 py-4">

                                <form
                                    method="POST"
                                    action="{{ route('budgets.update', $budget) }}"
                                    class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-4 items-end"
                                >
                                    @csrf
                                    @method('PUT')


                                    {{-- Monthly limit --}}
                                    <div>

                                        <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                            Monthly limit
                                        </label>

                                        <div class="relative">

                                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#C9A227]">
                                                ₹
                                            </span>

                                            <input
                                                type="number"
                                                name="monthly_limit"
                                                step="0.01"
                                                min="1"
                                                value="{{ $budget->monthly_limit }}"
                                                class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm pl-7 pr-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                            >

                                        </div>

                                        <p class="text-[10px] text-[#EDE6D6]/35 mt-1">
                                            Maximum you want to spend.
                                        </p>

                                    </div>


                                    {{-- Alert threshold --}}
                                    <div>

                                        <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                            Alert threshold
                                        </label>

                                        <div class="relative">

                                            <input
                                                type="number"
                                                name="alert_threshold_percent"
                                                min="1"
                                                max="100"
                                                value="{{ $budget->alert_threshold_percent }}"
                                                class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm pl-3 pr-7 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                            >

                                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[#C9A227]">
                                                %
                                            </span>

                                        </div>

                                        <p class="text-[10px] text-[#EDE6D6]/35 mt-1">
                                            Alert when spending reaches this %.
                                        </p>

                                    </div>


                                    {{-- Save --}}
                                    <button
                                        type="submit"
                                        class="h-9 rounded-md bg-[#C9A227] px-5 text-xs font-medium text-[#15120E] hover:opacity-90 transition"
                                    >
                                        Save changes
                                    </button>

                                </form>


                                <div class="flex justify-end mt-3">

                                    <form
                                        method="POST"
                                        action="{{ route('budgets.destroy', $budget) }}"
                                        onsubmit="return confirm('Remove this budget?')"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="text-[11px] text-red-400/60 hover:text-red-400 transition"
                                        >
                                            Remove budget
                                        </button>

                                    </form>

                                </div>

                            </div>

                        </div>

                    @empty

                        <div class="rounded-xl border border-dashed border-[#C9A227]/20 bg-[#1D1911] px-6 py-10 text-center">

                            <div class="w-10 h-10 mx-auto rounded-lg bg-[#C9A227]/10 flex items-center justify-center text-[#C9A227]">
                                ₹
                            </div>

                            <h4 class="text-sm font-medium text-[#EDE6D6] mt-3">
                                No budgets yet
                            </h4>

                            <p class="text-xs text-[#EDE6D6]/40 mt-1">
                                Add a budget below to start tracking your spending.
                            </p>

                        </div>

                    @endforelse

                </div>

            </section>


            {{-- Add budget --}}
            <section>

                <div class="bg-[#1D1911] border border-[#C9A227]/20 rounded-xl overflow-hidden">

                    <div class="px-5 sm:px-6 py-4 border-b border-[#C9A227]/10">

                        <div class="flex items-center gap-3">

                            <div class="w-9 h-9 rounded-lg bg-[#C9A227]/10 border border-[#C9A227]/15 flex items-center justify-center text-[#C9A227]">
                                +
                            </div>

                            <div>
                                <h3 class="text-sm font-medium text-[#EDE6D6]">
                                    Add a budget
                                </h3>

                                <p class="text-[11px] text-[#EDE6D6]/40 mt-0.5">
                                    Create a spending limit for a category.
                                </p>
                            </div>

                        </div>

                    </div>


                    <div class="px-5 sm:px-6 py-5">

                        <form
                            method="POST"
                            action="{{ route('budgets.store') }}"
                            class="grid grid-cols-1 md:grid-cols-[1.4fr_1fr_1fr_auto] gap-4 items-end"
                        >
                            @csrf


                            {{-- Category --}}
                            <div>

                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Category
                                </label>

                                <select
                                    name="category"
                                    class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                >

                                    @unless ($hasOverallBudget)
                                        <option value="">Overall — all spending</option>
                                    @endunless

                                    @foreach ($availableCategories as $category)
                                        <option value="{{ $category }}">
                                            {{ $category }}
                                        </option>
                                    @endforeach

                                </select>

                            </div>


                            {{-- Monthly limit --}}
                            <div>

                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Monthly limit
                                </label>

                                <div class="relative">

                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#C9A227]">
                                        ₹
                                    </span>

                                    <input
                                        type="number"
                                        name="monthly_limit"
                                        step="0.01"
                                        min="1"
                                        required
                                        placeholder="5,000"
                                        class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm pl-7 pr-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                    >

                                </div>

                            </div>


                            {{-- Alert --}}
                            <div>

                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Alert at
                                </label>

                                <div class="relative">

                                    <input
                                        type="number"
                                        name="alert_threshold_percent"
                                        min="1"
                                        max="100"
                                        value="80"
                                        class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm pl-3 pr-7 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                    >

                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-[#C9A227]">
                                        %
                                    </span>

                                </div>

                            </div>


                            {{-- Add --}}
                            <button
                                type="submit"
                                class="h-9 rounded-md bg-[#C9A227] px-5 text-xs font-medium text-[#15120E] hover:opacity-90 transition whitespace-nowrap"
                            >
                                + Add budget
                            </button>

                        </form>

                    </div>

                </div>

            </section>

        </div>
    </div>
</x-app-layout>