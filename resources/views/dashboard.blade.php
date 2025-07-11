@extends('layouts.app')
@section('title')<title>Doc-Track</title> @endsection

@section('content')
<div class="d-flex" style="min-height: 100vh;">

    {{-- Sidebar --}}
    <div class="sidebar p-4" style="width: 240px;">
        <div class="text-center mb-4">
            <img src="{{ asset('storage/prti_logo.png') }}" alt="DOST Logo" class="img-fluid mb-2" width="64">
            <h5 class="text-light">DOST - PTRI</h5>
        </div>

        <ul class="nav nav-pills flex-column" id="tabMenu">
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#profile">👤 My Profile</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="{{ route('scan.qr') }}">📷 Scan QR Code</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#track">📄 Track Document</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#upload">📤 Upload Document</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#my-files">📁 Files Uploaded by Me</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#ptri-files">📂 PTRI Files</a>
            </li>
            <li class="nav-item mt-3">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-outline-light w-100">Logout</button>
                </form>
            </li>
        </ul>
    </div>

    {{-- Main Content --}}
    <div class="flex-grow-1 p-4" style="background-color: #073263;">

        {{-- Summary Cards --}}
        <div class="row mb-4">
            @php
                $stats = [
                    ['label' => 'Incoming Docs', 'count' => $incomingCount, 'class' => 'primary'],
                    ['label' => 'Pending Docs', 'count' => $pendingCount, 'class' => 'warning'],
                    ['label' => 'Received Docs', 'count' => $receivedCount, 'class' => 'success'],
                    ['label' => 'Ended Docs', 'count' => $endedCount, 'class' => 'danger'],
                ];
            @endphp

            @foreach($stats as $stat)
                <div class="col-md-3 mb-3">
                    <div class="card text-white bg-{{ $stat['class'] }} shadow-sm">
                        <div class="card-body text-center">
                            <h3>{{ $stat['count'] }}</h3>
                            <p class="mb-0">{{ $stat['label'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Tab Content --}}
        <div class="tab-content">

            {{-- Profile Tab --}}
            <div class="tab-pane fade" id="profile">
                <h2 class="mb-3">My Profile</h2>
                <div class="card bg-light text-dark p-4">
                    <div class="d-flex align-items-center mb-3">
                        <img src="{{ Auth::user()->profile_photo_url ?? 'https://www.gravatar.com/avatar/' . md5(strtolower(trim(Auth::user()->email))) . '?s=100&d=identicon' }}"
                            alt="Profile" style="width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 2px solid #343a40;">
                        <div class="ms-3">
                            <h5 class="mb-0">{{ Auth::user()->name }}</h5>
                            <p class="text-muted mb-1">{{ Auth::user()->email }}</p>
                            <a href="{{ route('profile.edit') }}" class="btn btn-sm btn-outline-primary mt-1">Edit Profile</a>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Menu
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('divisions.index') }}">Divisions</a></li>
                            <li><a class="dropdown-item" href="{{ route('sections.index') }}">Sections</a></li>
                            <li><a class="dropdown-item" href="{{ route('employees.index') }}">Employees</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- Track Tab --}}
            <div class="tab-pane fade" id="track">
                <h2 class="mb-3 text-light">Track a Document</h2>
                <form action="{{ route('documents.viewByCode') }}" method="GET">
                    <div class="mb-3">
                        <label for="code" class="form-label text-light">Enter Tracking Code</label>
                        <input type="text" name="code" class="form-control" placeholder="Enter tracking code" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Track</button>
                </form>
            </div>

            {{-- Upload Tab --}}
            <div class="tab-pane fade show active" id="upload">
                <h2 class="mb-3 text-light">Upload Document</h2>
                <a href="{{ route('documents.create') }}" class="btn btn-primary">Go to Upload Page</a>
            </div>

            {{-- My Files Tab --}}
            <div class="tab-pane fade" id="my-files">
                <h2 class="mb-3 text-light">Files Uploaded by Me</h2>
                @include('documents.partials.my-files', ['documents' => $myDocuments])
            </div>

            {{-- PTRI Files Tab --}}
            <div class="tab-pane fade" id="ptri-files">
                <h2 class="mb-3 text-light">PTRI Files</h2>
                <table class="table table-dark table-hover table-bordered shadow-sm rounded">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Tracking Code</th>
                            <th>Type</th>
                            <th>Uploader</th>
                            <th>Status</th>
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
                                    <span class="badge bg-{{ 
                                        $doc->current_status === 'received' ? 'success' : 
                                        ($doc->current_status === 'pending' ? 'warning' : 
                                        ($doc->current_status === 'ended' ? 'danger' : 
                                        ($doc->current_status === 'incoming' ? 'primary' : 'secondary')))
                                    }}">
                                        {{ ucfirst($doc->current_status) }}
                                    </span>
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
@endsection
