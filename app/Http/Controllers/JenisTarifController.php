<?php

namespace App\Http\Controllers;

use App\Http\Requests\JenisTarif\Store;
use App\Http\Requests\JenisTarif\Update;
use App\Http\Services\JenisTarifService;
use App\Models\JenisTarif;

class JenisTarifController extends Controller
{
    public function __construct(protected JenisTarifService $jenisTarifService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $jenisTarifs = $this->jenisTarifService->getJenisTarifs($request->only(['search', 'status', 'sort', 'direction', 'per_page']));
        return view('jenis-tarifs.index', compact('jenisTarifs'));
    }

    public function create()
    {
        return view('jenis-tarifs.create');
    }

    public function store(Store $request)
    {
        $this->jenisTarifService->createJenisTarif($request->validated());
        return redirect()->route('jenis-tarifs.index')->with('success', 'Jenis tarif berhasil dibuat!');
    }

    public function show(JenisTarif $jenis_tarif)
    {
        $jenis_tarif->load('tarifs.provider', 'tarifs.service', 'tarifs.serviceClass');
        return view('jenis-tarifs.show', compact('jenis_tarif'));
    }

    public function edit(JenisTarif $jenis_tarif)
    {
        return view('jenis-tarifs.edit', compact('jenis_tarif'));
    }

    public function update(Update $request, JenisTarif $jenis_tarif)
    {
        $this->jenisTarifService->updateJenisTarif($jenis_tarif, $request->validated());
        return redirect()->route('jenis-tarifs.index')->with('success', 'Jenis tarif berhasil diperbarui!');
    }

    public function destroy(JenisTarif $jenis_tarif)
    {
        $result = $this->jenisTarifService->deleteJenisTarif($jenis_tarif);
        if ($result === false) {
            return redirect()->route('jenis-tarifs.index')->with('error', 'Jenis tarif tidak dapat dihapus karena masih digunakan pada data tarif!');
        }

        return redirect()->route('jenis-tarifs.index')->with('success', 'Jenis tarif berhasil dihapus!');
    }
}
