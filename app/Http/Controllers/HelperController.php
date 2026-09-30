<?php

namespace App\Http\Controllers;

use App\Http\Requests\Helper\Store;
use App\Http\Requests\Helper\Update;
use App\Http\Services\HelperService;
use App\Models\Helper;

class HelperController extends Controller
{
    public function __construct(protected HelperService $helperService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $helpers = $this->helperService->getHelpers($request->only(['search', 'status', 'sort', 'direction', 'per_page']));
        return view('helpers.index', compact('helpers'));
    }

    public function create()
    {
        return view('helpers.create');
    }

    public function store(Store $request)
    {
        $this->helperService->createHelper($request->validated());
        return redirect()->route('helpers.index')->with('success', 'Helper berhasil dibuat!');
    }

    public function show(Helper $helper)
    {
        return view('helpers.show', compact('helper'));
    }

    public function edit(Helper $helper)
    {
        return view('helpers.edit', compact('helper'));
    }

    public function update(Update $request, Helper $helper)
    {
        $this->helperService->updateHelper($helper, $request->validated());
        return redirect()->route('helpers.index')->with('success', 'Helper berhasil diperbarui!');
    }

    public function destroy(Helper $helper)
    {
        $this->helperService->deleteHelper($helper);

        return redirect()->route('helpers.index')->with('success', 'Helper berhasil dihapus!');
    }
}
