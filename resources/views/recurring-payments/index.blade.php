<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-display text-xl text-[#EDE6D6]">
                Recurring Payments
            </h2>
            <p class="text-xs text-[#EDE6D6]/50 mt-1">
                Declare upcoming payments and get reminded when they're due.
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


            {{-- Existing recurring payments --}}
            <section>

                <div class="flex items-end justify-between mb-4">
                    <div>
                        <h3 class="font-display text-lg text-[#EDE6D6]">
                            Your recurring payments
                        </h3>

                        <p class="text-xs text-[#EDE6D6]/45 mt-1">
                            Reminders go out on WhatsApp when a payment is due.
                        </p>
                    </div>

                    <span class="text-xs text-[#EDE6D6]/40">
                        {{ count($recurringPayments) }}
                        {{ count($recurringPayments) === 1 ? 'payment' : 'payments' }}
                    </span>
                </div>


                <div class="space-y-4">

                    @forelse ($recurringPayments as $payment)

                        @php
                            $intervalLabel = $payment->interval_count > 1
                                ? "Every {$payment->interval_count} {$payment->interval_unit}s"
                                : "Every {$payment->interval_unit}";

                            if ($payment->interval_unit === 'month' && $payment->day_of_month) {
                                $intervalLabel .= " (day {$payment->day_of_month})";
                            } elseif ($payment->interval_unit === 'week' && $payment->day_of_week !== null) {
                                $intervalLabel .= ' (' . \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($payment->day_of_week)->format('l') . ')';
                            }

                            $isOverdue = $payment->active && $payment->next_due_date->isPast() && !$payment->next_due_date->isToday();
                        @endphp

                        <div class="bg-[#1D1911] border border-[#C9A227]/20 rounded-xl overflow-hidden {{ !$payment->active ? 'opacity-50' : '' }}">

                            {{-- Header --}}
                            <div class="px-5 sm:px-6 py-5">

                                <div class="flex items-center justify-between gap-4">

                                    <div class="flex items-center gap-3 min-w-0">

                                        <div class="w-9 h-9 shrink-0 rounded-lg bg-[#C9A227]/10 border border-[#C9A227]/15 flex items-center justify-center text-[#C9A227]">
                                            🔁
                                        </div>

                                        <div class="min-w-0">
                                            <h4 class="text-sm font-medium text-[#EDE6D6] truncate">
                                                {{ $payment->name }}
                                            </h4>

                                            <p class="text-[11px] text-[#EDE6D6]/40 mt-0.5">
                                                {{ $payment->category }} · {{ $intervalLabel }}
                                            </p>
                                        </div>

                                    </div>


                                    {{-- Status --}}
                                    @if ($payment->missed_last_reminder)
                                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] text-amber-400 bg-amber-400/10 border-amber-400/20">
                                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                            Missed
                                        </span>
                                    @elseif (!$payment->active)
                                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] text-[#EDE6D6]/50 bg-[#EDE6D6]/5 border-[#EDE6D6]/15">
                                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                            Paused
                                        </span>
                                    @elseif ($isOverdue)
                                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] text-red-400 bg-red-400/10 border-red-400/20">
                                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                            Overdue
                                        </span>
                                    @else
                                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] text-emerald-400 bg-emerald-400/10 border-emerald-400/20">
                                            <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                            Scheduled
                                        </span>
                                    @endif

                                </div>


                                {{-- Summary --}}
                                <div class="mt-6 grid grid-cols-2 gap-4">

                                    <div>
                                        <p class="text-[11px] text-[#EDE6D6]/40">
                                            Amount
                                        </p>

                                        <p class="font-mono text-xl font-semibold text-[#EDE6D6] mt-1">
                                            ₹{{ number_format($payment->amount, 2) }}
                                        </p>
                                    </div>


                                    <div class="text-right">
                                        <p class="text-[11px] text-[#EDE6D6]/40">
                                            Next due
                                        </p>

                                        <p class="font-mono text-xl font-semibold text-[#EDE6D6] mt-1">
                                            {{ $payment->next_due_date->format('M j, Y') }}
                                        </p>
                                    </div>

                                </div>

                            </div>


                            {{-- Actions --}}
                            <div class="border-t border-[#C9A227]/10 px-5 sm:px-6 py-4 flex items-center justify-between">

                                <form
                                    method="POST"
                                    action="{{ route('recurring-payments.toggle', $payment) }}"
                                >
                                    @csrf
                                    <button
                                        type="submit"
                                        class="text-[11px] text-[#EDE6D6]/60 hover:text-[#EDE6D6] transition"
                                    >
                                        {{ $payment->active ? 'Pause' : 'Resume' }}
                                    </button>
                                </form>

                                <form
                                    method="POST"
                                    action="{{ route('recurring-payments.destroy', $payment) }}"
                                    onsubmit="return confirm('Remove this recurring payment?')"
                                >
                                    @csrf
                                    @method('DELETE')

                                    <button
                                        type="submit"
                                        class="text-[11px] text-red-400/60 hover:text-red-400 transition"
                                    >
                                        Remove
                                    </button>
                                </form>

                            </div>

                        </div>

                    @empty

                        <div class="rounded-xl border border-dashed border-[#C9A227]/20 bg-[#1D1911] px-6 py-10 text-center">

                            <div class="w-10 h-10 mx-auto rounded-lg bg-[#C9A227]/10 flex items-center justify-center text-[#C9A227]">
                                🔁
                            </div>

                            <h4 class="text-sm font-medium text-[#EDE6D6] mt-3">
                                No recurring payments yet
                            </h4>

                            <p class="text-xs text-[#EDE6D6]/40 mt-1">
                                Add one below, or just text the bot: "remind me to pay rent 15000 every month on the 5th."
                            </p>

                        </div>

                    @endforelse

                </div>

            </section>


            {{-- Add recurring payment --}}
            <section>

                <div class="bg-[#1D1911] border border-[#C9A227]/20 rounded-xl overflow-hidden">

                    <div class="px-5 sm:px-6 py-4 border-b border-[#C9A227]/10">

                        <div class="flex items-center gap-3">

                            <div class="w-9 h-9 rounded-lg bg-[#C9A227]/10 border border-[#C9A227]/15 flex items-center justify-center text-[#C9A227]">
                                +
                            </div>

                            <div>
                                <h3 class="text-sm font-medium text-[#EDE6D6]">
                                    Add a recurring payment
                                </h3>

                                <p class="text-[11px] text-[#EDE6D6]/40 mt-0.5">
                                    Get a WhatsApp reminder each time it's due.
                                </p>
                            </div>

                        </div>

                    </div>


                    <div class="px-5 sm:px-6 py-5">

                        <form
                            method="POST"
                            action="{{ route('recurring-payments.store') }}"
                            class="grid grid-cols-1 md:grid-cols-2 gap-4"
                        >
                            @csrf

                            {{-- Name --}}
                            <div>
                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Name
                                </label>

                                <input
                                    type="text"
                                    name="name"
                                    required
                                    placeholder="Rent"
                                    class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                >
                            </div>

                            {{-- Category --}}
                            <div>
                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Category
                                </label>

                                <select
                                    name="category"
                                    required
                                    class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                >
                                    @foreach ($categories as $category)
                                        <option value="{{ $category }}">{{ $category }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Amount --}}
                            <div>
                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Amount
                                </label>

                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-[#C9A227]">
                                        ₹
                                    </span>

                                    <input
                                        type="number"
                                        name="amount"
                                        step="0.01"
                                        min="0.01"
                                        required
                                        placeholder="15,000"
                                        class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm pl-7 pr-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                    >
                                </div>
                            </div>

                            {{-- Interval --}}
                            <div class="grid grid-cols-[auto_1fr] gap-2">

                                <div>
                                    <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                        Every
                                    </label>

                                    <input
                                        type="number"
                                        name="interval_count"
                                        min="1"
                                        max="365"
                                        value="1"
                                        class="w-16 h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm px-2 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                    >
                                </div>

                                <div>
                                    <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                        Interval
                                    </label>

                                    <select
                                        name="interval_unit"
                                        id="interval_unit"
                                        onchange="window.toggleIntervalFields()"
                                        class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                    >
                                        <option value="day">Day(s)</option>
                                        <option value="week">Week(s)</option>
                                        <option value="month" selected>Month(s)</option>
                                    </select>
                                </div>

                            </div>

                            {{-- Day of month (shown for month interval) --}}
                            <div id="day_of_month_field">
                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Day of month
                                </label>

                                <input
                                    type="number"
                                    name="day_of_month"
                                    min="1"
                                    max="31"
                                    placeholder="e.g. 5"
                                    class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] font-mono text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                >

                                <p class="text-[10px] text-[#EDE6D6]/35 mt-1">
                                    Clamped to the last day in short months.
                                </p>
                            </div>

                            {{-- Day of week (shown for week interval) --}}
                            <div id="day_of_week_field" class="hidden">
                                <label class="block text-[11px] font-medium text-[#EDE6D6]/60 mb-1.5">
                                    Day of week
                                </label>

                                <select
                                    name="day_of_week"
                                    class="w-full h-9 rounded-md bg-[#15120E] border border-[#C9A227]/25 text-[#EDE6D6] text-sm px-3 focus:border-[#C9A227]/60 focus:ring-1 focus:ring-[#C9A227]/20"
                                >
                                    <option value="0">Sunday</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5" selected>Friday</option>
                                    <option value="6">Saturday</option>
                                </select>
                            </div>

                            {{-- Add --}}
                            <div class="md:col-span-2 flex justify-end">
                                <button
                                    type="submit"
                                    class="h-9 rounded-md bg-[#C9A227] px-5 text-xs font-medium text-[#15120E] hover:opacity-90 transition whitespace-nowrap"
                                >
                                    + Add recurring payment
                                </button>
                            </div>

                        </form>

                    </div>

                </div>

            </section>

        </div>
    </div>

    <script>
        window.toggleIntervalFields = function () {
            const unit = document.getElementById('interval_unit').value;
            document.getElementById('day_of_month_field').classList.toggle('hidden', unit !== 'month');
            document.getElementById('day_of_week_field').classList.toggle('hidden', unit !== 'week');
        };
    </script>
</x-app-layout>