<?php

namespace App\Livewire\Doctor;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Livewire\WithPagination;


class UsersComponent extends Component {


use WithPagination;

   public $name, $email, $password, $role = '1', $userId;
    public $isOpen = false;
    public $isEdit = false;

    // Filter properties
    public $search = '';
    public $filterRole = ''; 

    // Reset pagination when search or filters change
    public function updatingSearch() { $this->resetPage(); }
    public function updatingFilterRole() { $this->resetPage(); }

    protected $rules = [
        'name' => 'required|min:3',
        'email' => 'required|email|unique:users,email',
        'role' => 'required|in:0,1,2,3,4,5,6',
        'password' => 'required|min:6',
    ];

    public function render()
    {
        $users = User::query()
            ->when($this->search, function($query) {
                $query->where(function($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('email', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->filterRole !== '', function($query) {
                $query->where('role', $this->filterRole);
            })
            ->latest()
            ->paginate(10);

        return view('livewire.doctor.users-component', [
            'users' => $users
        ])->layout('layouts.doctor.doctor-layout');
    }

    public function create()
    {
        $this->resetInputFields();
        $this->openModal();
        $this->isEdit = false;
    }

    public function openModal() { $this->isOpen = true; }
    public function closeModal() { $this->isOpen = false; }

    private function resetInputFields() {
        $this->name = ''; 
        $this->email = ''; 
        $this->password = '';
        $this->role = '1'; 
        $this->userId = '';
    }

    public function store()
    {
        // Security: Ensure user has permission to manage users
        if (!in_array(auth()->user()->role, ['0', '2', '3'])) {
            abort(403);
        }

        $validationRules = $this->rules;
        if($this->isEdit) {
            $validationRules['email'] = 'required|email|unique:users,email,' . $this->userId;
            $validationRules['password'] = 'nullable|min:6';
        }

        $this->validate($validationRules);

        User::updateOrCreate(['id' => $this->userId], [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'password' => $this->password ? Hash::make($this->password) : User::find($this->userId)->password,
        ]);

        $this->dispatch('notify', ...['type' => 'success', 'message' => $this->userId ? 'User Updated Successfully.' : 'User Created Successfully.']);
        $this->closeModal();
        $this->resetInputFields();
    }

    public function edit($id)
    {
        if (!in_array(auth()->user()->role, ['0', '2', '3'])) {
            abort(403);
        }

        $user = User::findOrFail($id);
        $this->userId = $id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->role;
        $this->isEdit = true;
        $this->openModal();
    }

    public function delete($id)
    {
        // Only Super Admin can delete
        if (auth()->user()->role !== '0') {
            $this->dispatch('notify', ...['type' => 'error', 'message' => 'Only Super Admins can delete users.']);
            return;
        }

        User::find($id)->delete();
        $this->dispatch('notify', ...['type' => 'success', 'message' => 'User Deleted Successfully.']);
    }
}
