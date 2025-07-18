@extends('layouts.app')

@section('content')
<div class="container py-5">
    <h2>Upload New Document</h2>

    {{-- Display validation errors if they exist --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data">
        @csrf

        {{-- Document Title --}}
        <div class="mb-3">
            <label for="title" class="form-label">Document Title</label>
            <input type="text" name="title" class="form-control" value="{{ old('title') }}" required>
        </div>

        {{-- File Input --}}
        <div class="mb-3">
            <label for="file" class="form-label">Choose File</label>
            <input type="file" name="file" class="form-control" required>
        </div>

        {{-- Document Type --}}
        <div class="mb-3">
            <label for="type" class="form-label">Document Type</label>
            <select name="type" class="form-select" required>
                <option value="">-- Select Type --</option>
                <option value="Confidential" @if(old('type') == 'Confidential') selected @endif>Confidential</option>
                <option value="Administrative Order" @if(old('type') == 'Administrative Order') selected @endif>Administrative Order</option>
                <option value="Special Order" @if(old('type') == 'Special Order') selected @endif>Special Order</option>
                <option value="Memo" @if(old('type') == 'Memo') selected @endif>Memo</option>
                <option value="TRACE docs" @if(old('type') == 'TRACE docs') selected @endif>TRACE docs</option>
                <option value="Contract of Service" @if(old('type') == 'Contract of Service') selected @endif>Contract of Service</option>
                <option value="Memorandum of Understanding" @if(old('type') == 'Memorandum of Understanding') selected @endif>Memorandum of Understanding</option>
                <option value="JRU" @if(old('type') == 'JRU') selected @endif>JRU</option>
                <option value="IPCR/DPCR" @if(old('type') == 'IPCR/DPCR') selected @endif>IPCR/DPCR</option>
            </select>
        </div>

        {{-- NEW: Visibility Selection --}}
        <div class="mb-3">
            <label class="form-label">Visibility</label>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="visibility" id="visibilityPublic" value="public" checked>
                <label class="form-check-label" for="visibilityPublic">
                    Public (Visible to everyone)
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="visibility" id="visibilityPrivate" value="private">
                <label class="form-check-label" for="visibilityPrivate">
                    Private (Visible only to specific users)
                </label>
            </div>
        </div>

        {{-- NEW: Recipients Selection (for private documents) --}}
        <div class="mb-3" id="recipientsContainer" style="display: none;">
            <label for="recipients" class="form-label">Select Recipients</label>
            <p class="text-muted small">Hold down Ctrl (or Cmd on Mac) to select multiple users.</p>
            <select name="recipients[]" id="recipients" class="form-select" multiple size="8">
                @foreach($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Submit --}}
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">Upload</button>
            <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const visibilityRadios = document.querySelectorAll('input[name="visibility"]');
        const recipientsContainer = document.getElementById('recipientsContainer');
        const recipientsSelect = document.getElementById('recipients');

        function toggleRecipients() {
            if (document.getElementById('visibilityPrivate').checked) {
                recipientsContainer.style.display = 'block';
                recipientsSelect.required = true;
            } else {
                recipientsContainer.style.display = 'none';
                recipientsSelect.required = false;
                // Clear selected options when switching to public
                for (let option of recipientsSelect.options) {
                    option.selected = false;
                }
            }
        }

        visibilityRadios.forEach(radio => {
            radio.addEventListener('change', toggleRecipients);
        });

        // Initial check in case of validation errors
        toggleRecipients();
    });
</script>
@endpush
