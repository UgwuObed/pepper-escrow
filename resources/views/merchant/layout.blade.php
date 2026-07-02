<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') - Pepper Escrow</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="flex h-screen overflow-hidden">
        <aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 bg-gray-900 text-white transform -translate-x-full transition-transform duration-200 lg:translate-x-0 lg:static lg:inset-auto">
            <div class="flex items-center justify-between h-16 px-6 border-b border-gray-700">
                <span class="text-xl font-bold">Pepper Escrow</span>
                <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="lg:hidden text-gray-400 hover:text-white">&times;</button>
            </div>
            <nav class="flex-1 overflow-y-auto px-4 py-4 space-y-1">
                @php
                    $nav = [
                        'merchant.dashboard' => ['Dashboard', '📊'],
                        'merchant.transactions' => ['Transactions', '📋'],
                        'merchant.wallets' => ['Wallets', '💰'],
                        'merchant.virtual-accounts' => ['Bank Transfer', '🏦'],
                        'merchant.subscription-plans' => ['Plans', '📦'],
                        'merchant.subscriptions' => ['Subscriptions', '🔄'],
                        'merchant.settlements' => ['Settlements', '✅'],
                        'merchant.revenue' => ['Revenue', '📈'],
                        'merchant.reward-programs' => ['Rewards', '🎁'],
                        'merchant.listing-fees' => ['Listing Fees', '💵'],
                        'merchant.transaction-types' => ['Transaction Types', '🏷️'],
                        'merchant.commission-rules' => ['Commission Rules', '📐'],
                        'merchant.api-keys' => ['API Keys', '🔑'],
                        'merchant.bank-accounts' => ['Bank Accounts', '🏧'],
                        'merchant.notifications' => ['Notifications', '🔔'],
                        'merchant.settings' => ['Settings', '⚙️'],
                        'merchant.advanced-settings' => ['Advanced', '🔧'],
                    ];
                @endphp
                @foreach ($nav as $route => $item)
                    <a href="{{ route($route) }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors {{ request()->routeIs($route) ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-800 hover:text-white' }}">
                        <span>{{ $item[1] }}</span>
                        <span>{{ $item[0] }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="px-4 py-4 border-t border-gray-700">
                <a href="{{ route('merchant.logout') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-300 hover:bg-red-600 hover:text-white transition-colors">
                    <span>🚪</span>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            <header class="bg-white shadow-sm border-b h-16 flex items-center justify-between px-6">
                <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="lg:hidden text-gray-600 hover:text-gray-900">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-600">{{ Auth::guard('merchant')->user()->business_name ?? 'Merchant' }}</span>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto p-6">
                @if (session('success'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">{{ session('success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif
                @yield('content')
            </main>
        </div>
    </div>
@stack('scripts')
</body>
</html>