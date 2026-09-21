<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Page;

class PageController extends Controller
{
    public function show(string $slug)
    {
        $page = Page::where('slug', $slug)->firstOrFail();

        $supportEmail = AppSetting::get('support_email') ?: 'support@speedpilotapp.com';
        $content = str_replace('{{SUPPORT_EMAIL}}', e($supportEmail), $page->content);

        return view('page', ['page' => $page, 'content' => $content]);
    }
}
