@extends('merchant.layout')
@section('title', 'Merchant Login')
@section('content')
<div class="flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold mb-2 text-center">Merchant Login</h1>
        <p class="text-gray-500 text-center mb-6">Sign in to manage your escrow integrations</p>
        @if (session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('merchant.login') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="email">Email</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="email" name="email" type="email" value="{{ old('email') }}" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="password">Password</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password" name="password" type="password" required>
            </div>
            <div class="mb-6 flex items-center">
                <input class="mr-2" id="remember" name="remember" type="checkbox">
                <label class="text-sm text-gray-700" for="remember">Remember me</label>
            </div>
            <button class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded hover:bg-green-700 mb-3" type="submit">Sign In</button>
        </form>
        <p class="text-center text-sm text-gray-600">Don't have an account? <a href="{{ route('merchant.register') }}" class="text-blue-600 hover:underline">Create one</a></p>
    </div>
</div>
@endsection
