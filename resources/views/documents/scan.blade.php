@extends('layouts.app')

@section('title')
    Scan QR Code | DocTrack
@endsection

@section('content')
<main class="d-flex align-items-center justify-content-center bg-dark text-white" style="min-height: 100vh;">
    <div class="container" style="max-width: 400px;">
        <div class="bg-primary p-4 rounded shadow text-center">
            <h1 class="h4 mb-3">📷 Scan Document QR Code</h1>

            {{-- QR Scanner --}}
            <div class="d-flex justify-content-center align-items-center" style="height: 250px; overflow: hidden; border-radius: 8px; background-color: #343a40;">
                <div id="reader" style="width: 250px; height: 250px; position: relative;"></div>
            </div>

            {{-- NEW: Status/Error Message Area --}}
            <div id="scanStatus" class="mt-3 small text-light">
                Align the QR code inside the square.
            </div>

            <a href="{{ route('dashboard') }}" class="btn btn-outline-light btn-sm mt-3">
                ← Back to Dashboard
            </a>
        </div>
    </div>
</main>
@endsection

@push('scripts')
<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const html5QrCode = new Html5Qrcode("reader");
        const scanStatus = document.getElementById('scanStatus');

        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            aspectRatio: 1.0,
            rememberLastUsedCamera: true
        };

        function onScanSuccess(decodedText, decodedResult) {
            // Stop the camera and show a success message
            scanStatus.innerHTML = `<span class="text-success">Success! Redirecting...</span>`;
            html5QrCode.stop().then(() => {
                let redirectUrl;

                // UPDATED LOGIC: Check if the scanned text is a full URL or just a code
                if (decodedText.startsWith('http')) {
                    // If it's a full URL, use it directly
                    redirectUrl = decodedText;
                } else {
                    // Otherwise, assume it's a code and build the correct URL
                    redirectUrl = `{{ url('/documents/view') }}/${decodedText}`;
                }
                
                window.location.href = redirectUrl;

            }).catch(err => {
                console.error("Error stopping camera", err);
                scanStatus.textContent = 'Error stopping camera.';
            });
        }

        function onScanFailure(error) {
            // This function is called frequently, so we don't log anything here
            // to avoid spamming the console.
        }

        // Start the camera
        Html5Qrcode.getCameras().then(devices => {
            if (devices && devices.length) {
                html5QrCode.start(
                    { facingMode: "environment" }, // Prioritize the rear camera
                    config,
                    onScanSuccess,
                    onScanFailure
                ).catch(err => {
                    console.error("Error starting camera", err);
                    scanStatus.innerHTML = `<span class="text-danger">Error starting camera. Please grant permission and refresh.</span>`;
                });
            } else {
                scanStatus.innerHTML = `<span class="text-danger">No camera found on this device.</span>`;
            }
        }).catch(err => {
            console.error("Camera access error", err);
            scanStatus.innerHTML = `<span class="text-danger">Could not access camera. Please ensure you are on a secure (https) connection and have granted permissions.</span>`;
        });
    });
</script>
@endpush
