@extends('layouts.admin')

@section('title', 'Profile')

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Profile</h1>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg mb-6">
        <div class="text-sm text-slate-500">Logged in as</div>
        <div class="font-medium">{{ $user->email }}</div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <h2 class="font-medium mb-4">Change password</h2>

        @if ($errors->any())
            <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.profile.password') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium text-slate-700">Current password</label>
                <input type="password" name="current_password" required
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">New password</label>
                <input type="password" name="password" required minlength="10"
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Confirm new password</label>
                <input type="password" name="password_confirmation" required minlength="10"
                       class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-2 text-sm font-medium">
                Update password
            </button>
        </form>
    </div>
@endsection
