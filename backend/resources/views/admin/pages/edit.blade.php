@extends('layouts.admin')

@section('title', "Edit {$page->title}")

@section('content')
    <a href="{{ route('admin.pages.index') }}" class="text-sm text-slate-500 hover:underline">&larr; Back to pages</a>
    <h1 class="text-2xl font-semibold mt-2 mb-2">Edit {{ $page->title }}</h1>
    <p class="text-sm text-slate-500 mb-6">
        Live at <a href="/{{ $page->slug }}" target="_blank" class="underline">/{{ $page->slug }}</a>.
        Content is raw HTML (paragraphs, headings, lists, links) - use
        <code class="bg-slate-100 px-1 rounded">{{ '{{SUPPORT_EMAIL}}' }}</code> anywhere you want the
        support email from Settings inserted automatically.
    </p>

    @if ($errors->any())
        <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm max-w-2xl">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.pages.update', $page) }}" class="bg-white rounded-lg shadow p-6 space-y-5 max-w-2xl">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-slate-700">Title</label>
            <input type="text" name="title" value="{{ old('title', $page->title) }}" required
                   class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-sm">
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700">Content (HTML)</label>
            <textarea name="content" rows="24" required
                      class="mt-1 w-full rounded-md border-slate-300 shadow-sm text-xs font-mono leading-relaxed">{{ old('content', $page->content) }}</textarea>
        </div>

        <button type="submit" class="bg-slate-900 text-white rounded-md px-4 py-2 text-sm font-medium">
            Save
        </button>
    </form>
@endsection
