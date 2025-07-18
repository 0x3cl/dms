@extends('layouts.app')

@section('title')
    Doc-Track
@endsection

@section('content')
<div class="d-flex flex-column flex-md-row" style="min-height: 100vh;">
    {{-- Sidebar / Offcanvas --}}
    <nav class="sidebar bg-[#051e40] text-white p-3 d-none d-md-block" style="width: 240px;">
        <div class="text-center mb-4">
            <img src="{{ asset('ptri.png') }}" alt="DOST Logo" class="img-fluid mb-2" width="64">
            <h5>DOST - PTRI</h5>
        </div>

        <ul class="nav nav-pills flex-column" id="tabMenu">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#profile"><i class="fa-solid fa-user"></i> My Profile</a></li>
            <li class="nav-item"><a class="nav-link" href="{{ route('scan.qr') }}"><i class="fa-solid fa-camera"></i> Scan QR Code</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#track"><i class="fa-solid fa-magnifying-glass"></i> Track Document</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#upload"><i class="fa-solid fa-file-arrow-up"></i>  Upload Document</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#my-files"><i class="fa-regular fa-folder"></i> Files Uploaded by Me</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#docs-received"><i class="fa-solid fa-inbox"></i> Docs Received</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ptri-files"><i class="fa-regular fa-folder-open"></i> PTRI Files</a></li>
            <li class="nav-item mt-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-outline-light w-100">Logout</button>
                </form>
            </li>
        </ul>
    </nav>

    {{-- Offcanvas for small devices --}}
    <div class="d-md-none p-2 bg-dark text-white">
        <button class="btn btn-outline-light" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar">
            ☰ Menu
        </button>

        <div class="offcanvas offcanvas-start bg-[#051e40]" tabindex="-1" id="mobileSidebar">
            <div class="offcanvas-header text-white">
                <h5 class="offcanvas-title">Menu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
            </div>
            <div class="offcanvas-body">
                <ul class="nav nav-pills flex-column" id="tabMenuMobile">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#profile"><i class="fa-solid fa-user"></i> My Profile</a></li>
                    <li class="nav-item"><a class="nav-link" href="{{ route('scan.qr') }}"><i class="fa-solid fa-camera"></i> Scan QR Code</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#track"><i class="fa-solid fa-magnifying-glass"></i> Track Document</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#upload"><i class="fa-solid fa-file-arrow-up"></i> Upload Document</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#my-files"><i class="fa-regular fa-folder"></i> Files Uploaded by Me</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#docs-received"><i class="fa-solid fa-inbox"></i> Docs Received</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#ptri-files"><i class="fa-regular fa-folder-open"></i> PTRI Files</a></li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="flex-grow-1 p-3" style="background-color: #073263;">
        
        {{-- NEW: Session Alerts for error messages --}}
        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Dashboard Summary --}}
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-4">
                <div class="card shadow-sm border-0 text-white bg-primary">
                    <div class="card-body text-center">
                        <h4 class="card-title"><i class="fa-solid fa-check"></i> Docs Uploaded</h4>
                        <h2 class="fw-bold">{{ $outgoingCount ?? 0 }}</h2>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card shadow-sm border-0 text-white bg-success">
                    <div class="card-body text-center">
                        <h4 class="card-title"><i class="fa-solid fa-envelope-circle-check"></i> Docs Received</h4>
                        <h2 class="fw-bold">{{ $documentsReceivedCount ?? 0 }}</h2>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-4">
                <div class="card shadow-sm border-0 text-white bg-danger">
                    <div class="card-body text-center">
                        <h4 class="card-title"><i class="fa-solid fa-xmark"></i> Docs Ended</h4>
                        <h2 class="fw-bold">{{ $endedCount ?? 0 }}</h2>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab Content --}}
        <div class="tab-content">
            <div class="tab-pane fade show active" id="profile">
                <h2 class="mb-3 text-light">My Profile</h2>
                <div class="card bg-light">
                    <div class="card-body">
                        <p><strong>Name:</strong> {{ auth()->user()->name }}</p>
                        <p><strong>Division:</strong> {{ auth()->user()->division->name ?? 'N/A' }}</p>
                        <p><strong>Section:</strong> {{ auth()->user()->section->name ?? 'N/A' }}</p>
                        <div class="dropdown mt-3">
                            <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa-solid fa-bars"></i>Manage Data
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="{{ route('divisions.index') }}">Divisions</a></li>
                                <li><a class="dropdown-item" href="{{ route('sections.index') }}">Sections</a></li>
                                <li><a class="dropdown-item" href="{{ route('employees.index') }}">Employees</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="upload">
                <h2 class="mb-3 text-light">Upload Document</h2>
                <a href="{{ route('documents.create') }}" class="btn btn-primary">Go to Upload Page</a>
            </div>

            <div class="tab-pane fade" id="track">
                <h2 class="mb-3 text-light">Track a Document</h2>
                <form action="{{ route('documents.viewByCode') }}" method="GET">
                    <div class="mb-3">
                        <label for="code" class="form-label text-light">Enter Tracking Code</label>
                        <input type="text" id="code" name="code" class="form-control" placeholder="Enter tracking code" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Track</button>
                </form>
            </div>

            <div class="tab-pane fade" id="my-files">
                <h2 class="mb-3 text-light">Files Uploaded by Me</h2>
                @include('documents.partials.my-files', ['documents' => $myDocuments])
            </div>

            <div class="tab-pane fade" id="docs-received">
                <h2 class="mb-3 text-light">Documents I've Handled</h2>
                @include('documents.partials.received-files', ['receivedDocuments' => $receivedDocuments])
            </div>

            <div class="tab-pane fade" id="ptri-files">
                <h2 class="mb-3 text-light">PTRI Files</h2>
                <div class="table-responsive">
                    <table class="table table-white table-hover table-bordered shadow-sm rounded">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Tracking Code</th>
                                <th>Type</th>
                                <th>Uploader</th>
                                <th>Initial Location</th>
                                <th>Date Uploaded</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($allDocuments as $doc)
                                <tr>
                                    <td>{{ $doc->title }}</td>
                                    <td>{{ $doc->code }}</td>
                                    <td>{{ ucfirst($doc->type) }}</td>
                                    <td>{{ $doc->user->name ?? 'N/A' }}</td>
                                    <td>
                                        {{ $doc->user?->division?->name ?? 'N/A' }} - 
                                        {{ $doc->user?->section?->name ?? 'N/A' }}
                                    </td>
                                    <td>{{ $doc->created_at->format('Y-m-d') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
