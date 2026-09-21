<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Long-form static content (privacy policy, terms, etc.) editable from
 * /admin/pages instead of being hardcoded in a Blade view - a wording
 * change or App Store review request shouldn't need a code deploy.
 */
class Page extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'title', 'content'];
}
