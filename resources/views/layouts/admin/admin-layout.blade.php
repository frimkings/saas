{{-- Admin pages now use the Tailwind clinic layout. Admins get the admin menu; anyone else allowed on one of
     these pages (a Cashier on the cashier dashboard, say) gets their own menu. Kept as a name so components
     and <x-admin-layout> keep working. --}}
@include('layouts.clinic', ['menu' => \App\Support\ClinicNavigation::sharedLayout()[1]['menu'] ?? 'admin', 'slot' => $slot])
