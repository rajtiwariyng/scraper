<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Admin; // Use Admin model
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    public function index()
    {
        $users = Admin::whereHas('roles', function($q) {
            $q->where('guard_name', 'admin');
        })->get();

        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        //$roles = Role::whereIn('name', ['admin', 'super_admin'])->where('guard_name', 'admin')->get();
        $roles = Role::where('guard_name', 'admin')->get();

        return view('admin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:6|confirmed',
            'role' => 'required|string|exists:roles,name',
        ]);

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $role = Role::findByName($data['role'], 'admin'); // specify admin guard
        $admin->assignRole($role);

        return redirect()->route('admin.users.index')
                         ->with('success', 'Admin created successfully.');
    }

    public function edit(Admin $user)
    {
        $roles = Role::where('guard_name', 'admin')->get();

        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, Admin $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:admins,email,' . $user->id,
            'password' => 'nullable|string|min:6|confirmed',
            'role' => 'required|string|exists:roles,name',
        ]);

        $user->name = $data['name'];
        $user->email = $data['email'];
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $user->syncRoles([$data['role']]); // Spatie role assignment

        return redirect()->route('admin.users.index')
                         ->with('success', 'Admin updated successfully.');
    }

    public function destroy(Admin $user)
    {
        if ($user->id === auth('admin')->id()) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')
                         ->with('success', 'Admin user deleted successfully.');
    }
}
