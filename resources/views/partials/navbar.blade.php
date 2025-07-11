<nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #2a4a8b;">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="{{ url('/') }}">
      <img src="{{ asset('dost-logo.png') }}" alt="DOST Logo" style="height: 40px; margin-right: 8px;" />
      DOST - PTRI
    </a>

    <!-- Dropdown menu -->
    <div class="dropdown ms-auto">
      <button 
        class="btn btn-outline-light dropdown-toggle" 
        type="button" 
        id="navbarDropdownMenuButton" 
        data-bs-toggle="dropdown" 
        aria-expanded="false"
      >
        Menu
      </button>
     <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdownMenuButton">
  <li><a class="dropdown-item" href="{{ route('departments.index') }}">Departments</a></li>
  <li><a class="dropdown-item" href="{{ route('sections.index') }}">Sections</a></li>
  <li><a class="dropdown-item" href="{{ route('employees.index') }}">Employees</a></li>
  <li><a class="dropdown-item" href="{{ route('documents.index') }}">Documents</a></li>
  <li><hr class="dropdown-divider"></li>
  <li>
    <form method="POST" action="{{ route('logout') }}" onsubmit="return confirm('Are you sure you want to logout?')">
      @csrf
      <button type="submit" class="dropdown-item text-danger">
        Logout
      </button>
    </form>
  </li>
</ul>

    </div>
  </div>
</nav>
