<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('admin.settings.activity', [
            'entries' => ActivityLog::query()
                ->with('actor', 'client')
                ->when($request->query('q'), fn ($query, string $term) => $query->where('description', 'like', "%{$term}%"))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }
}
