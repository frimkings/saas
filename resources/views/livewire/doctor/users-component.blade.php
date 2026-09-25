<div class="p-6">
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <div class="flex flex-1 gap-2 w-full md:w-auto">
            <input wire:model.live.debounce.300ms="search" 
                   type="text" 
                   placeholder="Search name or email..." 
                   class="border rounded-lg px-4 py-2 w-full max-w-xs focus:ring-2 focus:ring-blue-400 outline-none">

            <select wire:model.live="filterRole" class="border rounded-lg px-4 py-2 outline-none">
                <option value="">All Roles</option>
                <option value="0">Super Admin</option>
                <option value="1">User</option>
                <option value="2">Editor</option>
                <option value="3">Manager</option>
                <option value="4">Guest</option>
                <option value="5">Developer</option>
                <option value="6">Analyst</option>
            </select>
        </div>

        @if(in_array(auth()->user()->role, ['0', '2', '3']))
        <button wire:click="create()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg shadow transition">
            + Create New User
        </button>
        @endif
    </div>

    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($users as $user)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $user->name }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $user->email }}</td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                            {{ $user->role_name }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        @if(in_array(auth()->user()->role, ['0', '2', '3']))
                            <button wire:click="edit({{ $user->id }})" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</button>
                        @endif

                        @if(auth()->user()->role === '0')
                            <button wire:click="delete({{ $user->id }})" 
                                    onclick="confirm('Are you sure?') || event.stopImmediatePropagation()" 
                                    class="text-red-600 hover:text-red-900">Delete</button>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-10 text-center text-gray-500 italic">No users found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

    @if($isOpen)
    <div class="fixed inset-0 z-50 flex items-center justify-center overflow-x-hidden overflow-y-auto outline-none focus:outline-none bg-black bg-opacity-50">
        <div class="relative w-full max-w-md mx-auto my-6">
            <div class="bg-white rounded-lg shadow-lg relative flex flex-col w-full outline-none focus:outline-none p-6">
                <h3 class="text-2xl font-semibold mb-4">{{ $isEdit ? 'Update User' : 'Create New User' }}</h3>
                
                <form wire:submit="store">
                    <div class="mb-4">
                        <label class="block text-sm font-bold mb-2">Name</label>
                        <input type="text" wire:model.live="name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-400 outline-none">
                        @error('name') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-bold mb-2">Email</label>
                        <input type="email" wire:model.live="email" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-400 outline-none">
                        @error('email') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-bold mb-2">Role</label>
                        <select wire:model.live="role" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-400 outline-none">
                            <option value="0">Super Admin</option>
                            <option value="1">User</option>
                            <option value="2">Editor</option>
                            <option value="3">Manager</option>
                            <option value="4">Guest</option>
                            <option value="5">Developer</option>
                            <option value="6">Analyst</option>
                        </select>
                        @error('role') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="mb-4">
                        <label class="block text-sm font-bold mb-2">Password {{ $isEdit ? '(Leave blank to keep current)' : '' }}</label>
                        <input type="password" wire:model.live="password" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-400 outline-none">
                        @error('password') <span class="text-red-500 text-xs">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center justify-end pt-4 border-t">
                        <button type="button" wire:click="closeModal()" class="text-gray-500 px-4 py-2 mr-2">Cancel</button>
                        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded shadow hover:bg-blue-700">
                            {{ $isEdit ? 'Update' : 'Save' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
</div>