<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketDepartment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.departments', [
            'departments' => TicketDepartment::query()->withCount('tickets')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        TicketDepartment::create($this->validated($request));

        return back()->with('status', __('Department added.'));
    }

    public function update(Request $request, TicketDepartment $department): RedirectResponse
    {
        $department->update($this->validated($request));

        return back()->with('status', __('Department saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_visible' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]) + ['sort_order' => 0];
    }
}
