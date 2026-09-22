<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function index()
    {
        $user = auth()->user();
        return view('profile.view', compact('user'));
    }
}
