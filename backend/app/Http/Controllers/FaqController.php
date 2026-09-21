<?php

namespace App\Http\Controllers;

use App\Models\FaqItem;

class FaqController extends Controller
{
    public function index()
    {
        $items = FaqItem::where('published', true)->orderBy('sort_order')->get();

        return view('faq', compact('items'));
    }
}
