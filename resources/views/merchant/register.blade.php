@extends('merchant.layout')
@section('title', 'Register')
@section('content')
<div class="flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-lg">
        <h1 class="text-2xl font-bold mb-2 text-center">Create Your Account</h1>
        <p class="text-gray-500 text-center mb-6">Join Pepper Escrow as a merchant</p>
        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('merchant.register') }}">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="business_name">Business Name</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" required>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="email">Email Address</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="email" name="email" type="email" value="{{ old('email') }}" required>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="phone">Phone</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="phone" name="phone" type="text" value="{{ old('phone') }}">
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="website">Website</label>
                    <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="website" name="website" type="url" value="{{ old('website') }}">
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="password">Password</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password" name="password" type="password" required>
            </div>
            <div class="mb-6">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="password_confirmation">Confirm Password</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-blue-300" id="password_confirmation" name="password_confirmation" type="password" required>
            </div>
            <button class="w-full bg-green-600 text-white font-bold py-2 px-4 rounded hover:bg-green-700 mb-3" type="submit">Create Account</button>
        </form>
        <p class="text-center text-sm text-gray-600">Already have an account? <a href="{{ route('merchant.login') }}" class="text-blue-600 hover:underline">Sign in</a></p>
    </div>
</div>
@endsection
