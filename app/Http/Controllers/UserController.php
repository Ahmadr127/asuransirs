<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Http\Requests\User\Store;
use App\Http\Requests\User\Update;
use App\Http\Services\UserService;
use Illuminate\Http\Request;


class UserController extends Controller
{
    public function __construct(protected UserService $userService){}

    public function index(Request $request)
    {
        $users = $this->userService->getUsers($request->only('search', 'date_from', 'date_to', 'per_page'));
        return view('users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::all();
        return view('users.create', compact('roles'));
    }

    public function store(Store $request)
    {
        $this->userService->createUser($request->validated());
        return redirect()->route('users.index')->with('success', 'User berhasil dibuat!');
    }

    public function edit(User $user)
    {
        $roles = Role::all();
        return view('users.edit', compact('user', 'roles'));
    }

    public function update(Update $request, User $user)
    {
        $this->userService->updateUser($user, $request->validated());
        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui!');
    }

    public function destroy(User $user)
    {
        // Mencegah user menghapus dirinya sendiri
        if ($user->id === auth()->id()) {
            return redirect()->route('users.index')->with('error', 'Tidak dapat menghapus akun sendiri!');
        }

        $this->userService->deleteUser($user);
        return redirect()->route('users.index')->with('success', 'User berhasil dihapus!');
    }
}
