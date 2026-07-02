@extends('backend.layout')
@section('title', 'User Management')
@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">User Management</h1>
        <div>
            <a href="{{ route('escrow.dashboard') }}" class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 mr-2">Back</a>
            <a href="{{ route('escrow.logout') }}" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
        </div>
    </div>

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

    <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-xl font-bold mb-4">Add New User</h2>
        <form method="POST" action="{{ route('escrow.users.add') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @csrf
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">First Name</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="firstName" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Last Name</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="lastName" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Email</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="email" type="email" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Password</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="password" type="password" required>
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Phone</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="phoneNo">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2">Job Title</label>
                <input class="w-full px-3 py-2 border border-gray-300 rounded" name="job_title">
            </div>
            <div class="md:col-span-2">
                <button class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700" type="submit">Add User</button>
            </div>
        </form>
    </div>

    <div class="bg-white p-6 rounded-lg shadow">
        <h2 class="text-xl font-bold mb-4">Existing Users</h2>
        <table class="w-full table-auto">
            <thead>
                <tr class="text-left text-gray-600 border-b">
                    <th class="pb-2">Name</th>
                    <th class="pb-2">Email</th>
                    <th class="pb-2">Phone</th>
                    <th class="pb-2">Role</th>
                    <th class="pb-2">Status</th>
                    <th class="pb-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users ?? [] as $user)
                <tr class="border-b">
                    <td class="py-2">{{ $user->firstName }} {{ $user->lastName }}</td>
                    <td class="py-2">{{ $user->email }}</td>
                    <td class="py-2">{{ $user->phoneNo ?? '-' }}</td>
                    <td class="py-2">{{ $user->account_type ?? 'user' }}</td>
                    <td class="py-2">{{ $user->status ? 'Active' : 'Blocked' }}</td>
                    <td class="py-2">
                        <form method="POST" action="{{ route('escrow.users.toggle') }}" class="inline" onsubmit="return confirm('Are you sure?')">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                            <button class="text-{{ $user->status ? 'red' : 'green' }}-600 hover:underline" type="submit">{{ $user->status ? 'Block' : 'Unblock' }}</button>
                        </form>
                        <form method="POST" action="{{ route('escrow.users.delete') }}" class="inline ml-2" onsubmit="return confirm('Delete this user?')">
                            @csrf
                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                            <button class="text-red-600 hover:underline" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-4 text-center text-gray-500">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
