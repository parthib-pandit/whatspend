<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Add Transaction</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow sm:rounded-lg">
                <form method="POST" action="{{ route('transactions.store') }}">
                    @csrf

                    <div>
                        <x-input-label for="type" value="Type" />
                        <select id="type" name="type" class="block mt-1 w-full rounded-md border-gray-300">
                            <option value="debit" @selected(old('type') === 'debit')>Debit</option>
                            <option value="credit" @selected(old('type') === 'credit')>Credit</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <x-input-label for="amount" value="Amount" />
                        <x-text-input id="amount" type="number" step="0.01" name="amount" class="block mt-1 w-full" :value="old('amount')" required />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <x-input-label for="category" value="Category" />
                        <select id="category" name="category" class="block mt-1 w-full rounded-md border-gray-300">
                            <option value="">-- none --</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->value }}" @selected(old('category') === $cat->value)>{{ $cat->value }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('category')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <x-input-label for="note" value="Note" />
                        <x-text-input id="note" type="text" name="note" class="block mt-1 w-full" :value="old('note')" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>

                    <div class="mt-4">
                        <x-input-label for="transaction_date" value="Date" />
                        <x-text-input id="transaction_date" type="date" name="transaction_date" class="block mt-1 w-full" :value="old('transaction_date', date('Y-m-d'))" required />
                        <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
                    </div>

                    <div class="flex justify-end mt-6">
                        <x-primary-button>Save</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>