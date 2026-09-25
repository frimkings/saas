{{-- <a href="index3.html" class="brand-link">
    <img src="{{asset('backend/dist/img/prime.jpg')}}" alt="AdminLTE Logo" class="brand-image img-circle elevation-3" >

    <span class="brand-text font-weight-light">PRIME EYE CARE</span>
  </a> --}}
@php($appSettings = $appSettings ?? \App\Models\Setting::getSettings())
<div class="brand-wrapper d-flex align-items-center py-2 px-3">
    @if($appSettings->clinic_logo)
        <img src="{{ asset('storage/' . $appSettings->clinic_logo) }}" 
             alt="Logo" 
             class="brand-image img-circle elevation-3 mr-2"
             style="width: 35px; height: 35px; object-fit: cover; flex-shrink: 0;">
    @else
        <i class="fas fa-clinic-medical text-white mr-2 fa-lg"></i> 
    @endif

    <span class="brand-text font-weight-bold text-white text-uppercase" 
          style="letter-spacing: 0.5px; line-height: 1.2; font-size: 0.9rem;">
        {{ $appSettings->clinic_name }}
    </span>
</div>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      {{-- <div class="image">
        <img src="{{asset('backend/dist/img/prime.jpg')}}" class="img-circle elevation-2" alt="User Image">
      </div> --}}
      <div class="info">
        <a href="#" class="d-block"> {{ Auth::user()->name }}</a>
      </div>
    </div>

    <!-- SidebarSearch Form -->
    <div class="form-inline">
      <div class="input-group" data-widget="sidebar-search">
        <input class="form-control form-control-sidebar" type="search" placeholder="Search" aria-label="Search">
        <div class="input-group-append">
          <button class="btn btn-sidebar">
            <i class="fas fa-search fa-fw"></i>
          </button>
        </div>
      </div>
    </div>
