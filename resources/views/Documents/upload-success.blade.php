@extends('layouts.app')

@section('content')
<div class="container py-5 text-center">
    <h2 class="mb-4">Document Uploaded Successfully!</h2>

    <p class="mb-2"><strong>Tracking Code:</strong> {{ $code }}</p>

    <div class="mb-4">
        <p><strong>QR Code:</strong></p>
    <img src="{{url('/storage/qrcodes/'.$file_name)}}"/>
    </div>

    <a href="{{ route('dashboard') }}" class="btn btn-primary">Back to Dashboard</a>
</div>
@endsection
