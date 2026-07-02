@extends('merchant.layout')
@section('title', 'Bank Accounts')
@section('content')
<h1 class="text-2xl font-bold mb-6">Bank Accounts</h1>

    <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h2 class="text-xl font-bold mb-4">Add Bank Account</h2>
        <form method="POST" action="{{ route('merchant.bank-accounts.store') }}">
            @csrf
            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="bank_name">Bank Name</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="bank_name" name="bank_name" type="text" required placeholder="e.g. GTBank">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="bank_code">Bank Code</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="bank_code" name="bank_code" type="text" required placeholder="e.g. 058">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="account_number">Account Number</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="account_number" name="account_number" type="text" required placeholder="0123456789">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="account_name">Account Name</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="account_name" name="account_name" type="text" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="currency">Currency</label>
                    <select class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="currency" name="currency">
                        <option value="NGN">NGN</option>
                        <option value="USD">USD</option>
                        <option value="GHS">GHS</option>
                        <option value="KES">KES</option>
                        <option value="ZAR">ZAR</option>
                    </select>
                </div>
                <div class="mb-4 flex items-end pb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_default" value="1" class="mr-2">
                        <span class="text-sm text-gray-700">Set as default account</span>
                    </label>
                </div>
            </div>
            <button class="bg-blue-600 text-white font-bold py-2 px-6 rounded hover:bg-blue-700" type="submit">Add Account</button>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Saved Accounts</h2>
        @forelse ($bankAccounts as $account)
            <div class="flex items-center justify-between p-4 border rounded mb-3 {{ $account->is_default ? 'bg-blue-50 border-blue-300' : 'bg-gray-50' }}">
                <div>
                    <p class="font-semibold">{{ $account->bank_name }}
                        @if ($account->is_default)
                            <span class="ml-2 text-xs bg-blue-600 text-white px-2 py-0.5 rounded">Default</span>
                        @endif
                    </p>
                    <p class="text-sm text-gray-600">{{ $account->account_name }} — {{ $account->account_number }}</p>
                    <p class="text-xs text-gray-400">{{ $account->bank_code }} · {{ $account->currency }}</p>
                </div>
                <div class="flex gap-2">
                    @if (!$account->is_default)
                        <form method="POST" action="{{ route('merchant.bank-accounts.default', $account->id) }}">
                            @csrf
                            <button class="text-xs text-blue-600 hover:underline" type="submit">Set Default</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('merchant.bank-accounts.destroy', $account->id) }}" onsubmit="return confirm('Remove this bank account?');">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-600 hover:underline" type="submit">Remove</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="text-gray-500 text-center py-6">No bank accounts added yet. Add one to receive settlements.</p>
        @endforelse
    </div>
@endsection
