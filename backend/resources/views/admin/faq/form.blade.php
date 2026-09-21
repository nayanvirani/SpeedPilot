@extends('layouts.admin')

@section('title', $item->exists ? 'Edit question' : 'New question')

@section('content')
    <a href="{{ route('admin.faq.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Back to FAQ</a>
    <h1 class="text-2xl font-semibold mt-2 mb-6">{{ $item->exists ? 'Edit question' : 'New question' }}</h1>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm max-w-2xl">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $item->exists ? route('admin.faq.update', $item) : route('admin.faq.store') }}" class="bg-white rounded-lg shadow p-6 space-y-5 max-w-2xl">
        @csrf
        @if ($item->exists) @method('PUT') @endif

        <div>
            <label class="block text-sm font-medium text-slate-700">Question</label>
            <input type="text" name="question" value="{{ old('question', $item->question) }}" required
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">Answer</label>
            <textarea name="answer" rows="6" required
                      class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">{{ old('answer', $item->answer) }}</textarea>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Sort order</label>
                <input type="number" min="0" name="sort_order" value="{{ old('sort_order', $item->sort_order) }}" required
                       class="mt-1 w-24 rounded-md border-slate-300 shadow-sm text-sm">
            </div>
            <div class="flex items-end pb-2">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="published" value="1" class="rounded border-slate-300" @checked($item->published)>
                    Published
                </label>
            </div>
        </div>

        <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-2 text-sm font-medium">
            Save
        </button>
    </form>
@endsection
