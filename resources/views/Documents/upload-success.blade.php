@extends('layouts.app')

@section('content')
<div class="container py-5 text-center">
    <h2 class="mb-4">Document Uploaded Successfully!</h2>

    <p class="mb-2"><strong>Tracking Code:</strong> {{ $code }}</p>

    <div class="mb-4">
        <p><strong>QR Code:</strong></p>
        <img src="{{ url('/storage/qrcodes/' . $file_name) }}" alt="QR Code" />
    </div>

    <div class="d-flex justify-content-center gap-3">
        <a href="{{ route('dashboard') }}" class="btn btn-primary">Back to Dashboard</a>
        <a href="{{ route('documents.qrcode.download', ['file_name' => $file_name]) }}" class="btn btn-success">
            Download QR Code
        </a>
    </div>
</div>
@endsection
