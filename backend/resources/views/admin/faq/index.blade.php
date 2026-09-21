@extends('layouts.admin')

@section('title', 'FAQ')

@section('content')
    <div class="flex items-center justify-between mb-2">
        <h1 class="text-2xl font-semibold">FAQ</h1>
        <a href="{{ route('admin.faq.create') }}" class="text-sm bg-slate-900 text-white rounded-md px-3 py-1.5">
            New question
        </a>
    </div>
    <p class="text-sm text-slate-500 mb-6">
        Live at <a href="/faq" target="_blank" class="underline">/faq</a>. Only published questions show there,
        in sort-order.
    </p>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-slate-500">
                <tr>
                    <th class="px-4 py-2">Order</th>
                    <th class="px-4 py-2">Question</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $item)
                    <tr class="border-t">
                        <td class="px-4 py-2 text-slate-500">{{ $item->sort_order }}</td>
                        <td class="px-4 py-2 font-medium">{{ $item->question }}</td>
                        <td class="px-4 py-2">
                            @if ($item->published)
                                <span class="text-green-600">Published</span>
                            @else
                                <span class="text-slate-400">Hidden</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right space-x-3">
                            <a href="{{ route('admin.faq.edit', $item) }}" class="text-slate-700 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.faq.destroy', $item) }}" class="inline"
                                  onsubmit="return confirm('Delete this question?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
