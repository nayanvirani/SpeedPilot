<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;

/**
 * Static content (privacy policy, terms, etc.) editable here instead of
 * hardcoded in a Blade view - only Page rows already seeded show up; this
 * doesn't let the admin create arbitrary new public pages/routes.
 */
class PageController extends Controller
{
    public function index()
    {
        $pages = Page::orderBy('title')->get();

        return view('admin.pages.index', compact('pages'));
    }

    public function edit(Page $page)
    {
        return view('admin.pages.edit', compact('page'));
    }

    public function update(Request $request, Page $page)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $page->update($data);

        return redirect()->route('admin.pages.index')->with('status', "{$page->title} updated.");
    }
}
