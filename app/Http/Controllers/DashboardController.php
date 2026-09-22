<?php

namespace App\Http\Controllers;

use App\Models\OrganizationUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $stats = [
            ['label' => 'Pengguna', 'value' => User::count(), 'icon' => 'bi-people-fill', 'color' => 'bg-sp-primary'],
            ['label' => 'Roles', 'value' => Role::count(), 'icon' => 'bi-person-fill-check', 'color' => 'bg-blue-500'],
            ['label' => 'Permissions', 'value' => Permission::count(), 'icon' => 'bi-key-fill', 'color' => 'bg-purple-500'],
            ['label' => 'Unit Organisasi', 'value' => OrganizationUnit::count(), 'icon' => 'bi-buildings-fill', 'color' => 'bg-emerald-500'],
        ];

        // Chart data: pengguna baru per bulan (6 bulan terakhir)
        $chartLabels = [];
        $chartData = [];
        for ($i = 5; $i >= 0; $i--) {
            $chartLabels[] = now()->subMonths($i)->translatedFormat('M Y');
            $chartData[] = User::whereBetween('created_at', [
                now()->subMonths($i)->startOfMonth(),
                now()->subMonths($i)->endOfMonth(),
            ])->count();
        }

        // Data untuk tabel dengan pencarian per kolom
        $tableRows = User::with('role')->limit(50)->get()->map(fn($u) => [
            'name' => $u->name,
            'nik' => $u->nik ?? '-',
            'username' => $u->username,
            'email' => $u->email,
            'role' => $u->role->display_name ?? '-',
            'created_at' => $u->created_at->format('d/m/Y'),
        ])->toArray();

        return view('dashboard', compact('user', 'stats', 'chartLabels', 'chartData', 'tableRows'));
    }
}
