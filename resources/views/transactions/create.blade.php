<x-app-layout>
    <x-slot name="header">New entry</x-slot>

    <div class="py-10">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-[#1D1911] border border-[#332C1F] rounded-md p-6">
                <form method="POST" action="{{ route('transactions.store') }}">
                    @csrf

                    <div>
                        <label for="type" class="block text-xs text-[#B9AF98] mb-1">Type</label>
                        <select id="type" name="type" class="block w-full rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] focus:border-[#C9A227] focus:ring-[#C9A227]">
                            <option value="debit" @selected(old('type') === 'debit')>Debit</option>
                            <option value="credit" @selected(old('type') === 'credit')>Credit</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <label for="amount" class="block text-xs text-[#B9AF98] mb-1">Amount</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[#C9A227] font-num">₹</span>
                            <input id="amount" type="number" step="0.01" name="amount" value="{{ old('amount') }}" required
                                class="block w-full pl-7 rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] font-num focus:border-[#C9A227] focus:ring-[#C9A227]">
                        </div>
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <label for="category" class="block text-xs text-[#B9AF98] mb-1">Category</label>
                        <select id="category" name="category" class="block w-full rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] focus:border-[#C9A227] focus:ring-[#C9A227]">
                            <option value="">-- none --</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->value }}" @selected(old('category') === $cat->value)>{{ $cat->value }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <label for="note" class="block text-xs text-[#B9AF98] mb-1">Note</label>
                        <input id="note" type="text" name="note" value="{{ old('note') }}"
                            class="block w-full rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] focus:border-[#C9A227] focus:ring-[#C9A227]">
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <label for="transaction_date" class="block text-xs text-[#B9AF98] mb-1">Date</label>
                        <input id="transaction_date" type="date" name="transaction_date" value="{{ old('transaction_date', date('Y-m-d')) }}" required
                            class="block w-full rounded-md bg-[#15120E] border-[#332C1F] text-[#EDE6D6] font-num focus:border-[#C9A227] focus:ring-[#C9A227]">
                        <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
                    </div>

                    <div class="flex justify-end mt-6 gap-3">
                        <a href="{{ route('transactions.index') }}" class="text-sm text-[#B9AF98] self-center hover:text-[#EDE6D6]">Cancel</a>
                        <button type="submit" class="rounded-md bg-[#C9A227] text-[#15120E] text-sm font-medium px-5 py-2 hover:bg-[#dab438] transition">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>