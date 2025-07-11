@extends('layouts.app') {{-- Replaces <x-app-layout> to match your layout file --}}

@section('title')
    <title>Scan QR Code | DocTrack</title>
@endsection

@section('content')
<main class="p-6 text-white bg-[#073263] min-h-screen">
    <div class="max-w-xl mx-auto bg-[#051e40] rounded shadow p-6 text-center">
        <h1 class="text-2xl font-bold mb-4">📷 Scan Document QR Code</h1>

        {{-- QR Scanner will render here --}}
        <div id="reader" class="mx-auto" style="width: 100%; max-width: 400px;"></div>

        <p class="mt-4 text-sm text-gray-300">
            Align the QR code within the box or choose an image from your device.
        </p>

        {{-- ✅ Back to Dashboard Button --}}
        <a href="{{ route('dashboard') }}" class="btn btn-outline-light mt-4">
            ← Back to Dashboard
        </a>
    </div>
</main>

@endsection

@push('scripts')
    <script>
        function onScanSuccess(decodedText, decodedResult) {
            // Redirect to the document view page with scanned code
            window.location.href = `/documents/view/${encodeURIComponent(decodedText)}`;
        }

        function onScanFailure(error) {
            // You can log errors or ignore them
            // console.warn(`Scan error: ${error}`);
        }

        document.addEventListener("DOMContentLoaded", function () {
            const html5QrcodeScanner = new Html5QrcodeScanner(
                "reader",
                { fps: 10, qrbox: { width: 250, height: 250 } },
                false
            );
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        });
    </script>
@endpush
