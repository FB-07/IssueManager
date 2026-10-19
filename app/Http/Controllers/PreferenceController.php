<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'theme' => ['sometimes', Rule::in(['dark', 'light'])],
            'locale' => ['sometimes', Rule::in(config('issuemanager.locales'))],
        ]);
        $request->user()->update($data);

        return $request->user()->only('theme', 'locale');
    }
}
