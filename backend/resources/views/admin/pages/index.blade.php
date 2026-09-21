@extends('layouts.admin')

@section('title', 'Pages')

@section('content')
    <h1 class="text-2xl font-semibold mb-2">Pages</h1>
    <p class="text-sm text-slate-500 mb-6">
        Public content pages, editable here without a redeploy. Live at
        <code class="bg-slate-100 px-1 rounded">speedpilot-production.up.railway.app/&lt;slug&gt;</code>.
    </p>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Title</th>
                    <th class="px-4 py-2">URL</th>
                    <th class="px-4 py-2">Last updated</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pages as $page)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-medium">{{ $page->title }}</td>
                        <td class="px-4 py-2">
                            <a href="/{{ $page->slug }}" target="_blank" class="text-slate-500 hover:underline font-mono text-xs">/{{ $page->slug }}</a>
                        </td>
                        <td class="px-4 py-2 text-slate-500">{{ $page->updated_at->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-right">
                            <a href="{{ route('admin.pages.edit', $page) }}" class="text-slate-700 hover:underline">Edit</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
