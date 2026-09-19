<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') - SpeedPilot</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 text-slate-900">
    <div class="min-h-screen flex">
        <aside class="w-56 bg-slate-900 text-slate-200 flex-shrink-0">
            <div class="px-5 py-4 text-lg font-semibold text-white">SpeedPilot Admin</div>
            <nav class="mt-2 flex flex-col">
                <a href="{{ route('admin.dashboard') }}" class="px-5 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.dashboard') ? 'bg-slate-800 text-white' : '' }}">Dashboard</a>
                <a href="{{ route('admin.stores.index') }}" class="px-5 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.stores.*') ? 'bg-slate-800 text-white' : '' }}">Stores</a>
                <a href="{{ route('admin.settings.edit') }}" class="px-5 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.settings.*') ? 'bg-slate-800 text-white' : '' }}">Settings</a>
            </nav>
            <form method="POST" action="{{ route('admin.logout') }}" class="px-5 py-4 mt-4">
                @csrf
                <button type="submit" class="text-sm text-slate-400 hover:text-white">Log out</button>
            </form>
        </aside>
        <main class="flex-1 p-8">
            <div class="max-w-6xl mx-auto">
                @if (session('status'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">
                        {{ session('status') }}
                    </div>
                @endif
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
