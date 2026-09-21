<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index()
    {
        $items = FaqItem::orderBy('sort_order')->get();

        return view('admin.faq.index', compact('items'));
    }

    public function create()
    {
        $item = new FaqItem(['sort_order' => (FaqItem::max('sort_order') ?? 0) + 1, 'published' => true]);

        return view('admin.faq.form', ['item' => $item]);
    }

    public function store(Request $request)
    {
        FaqItem::create($this->validated($request));

        return redirect()->route('admin.faq.index')->with('status', 'Question added.');
    }

    public function edit(FaqItem $item)
    {
        return view('admin.faq.form', compact('item'));
    }

    public function update(Request $request, FaqItem $item)
    {
        $item->update($this->validated($request));

        return redirect()->route('admin.faq.index')->with('status', 'Question updated.');
    }

    public function destroy(FaqItem $item)
    {
        $item->delete();

        return redirect()->route('admin.faq.index')->with('status', 'Question deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
            'sort_order' => 'required|integer|min:0',
            'published' => 'nullable|boolean',
        ]);

        $data['published'] = $request->boolean('published');

        return $data;
    }
}
