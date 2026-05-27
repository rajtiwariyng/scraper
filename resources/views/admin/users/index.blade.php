@extends('admin.layouts.app')

@section('title', 'Manage users')

@section('content')
<div class="container-fluid">
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary mb-3">Add Admin</a>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $admin)
                <tr @if($admin->hasRole('super_admin')) class="table-warning" @endif>
                    <td>{{ $admin->name }}</td>
                    <td>{{ $admin->email }}</td>
                    <td>{{ ucfirst($admin->getRoleNames()->first()) }}</td>
                    <td>
                        <a href="{{ route('admin.users.edit', $admin) }}" class="btn btn-sm btn-info">Edit</a>

                        @if(!$admin->hasRole('super_admin'))
                            <form action="{{ route('admin.users.destroy', $admin) }}" method="POST" style="display:inline-block;">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                            </form>
                        @else
                            <span class="text-muted">Protected</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
