@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Edit Document</h1>

    <form action="{{ route('documents.update', $document->id) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label for="title" class="form-label">Document Title</label>
            <input type="text" name="title" id="title" class="form-control" value="{{ $document->title }}" required>
        </div>

        <div class="mb-3">
            <label for="type" class="form-label">Document Type</label>
            <select name="type" id="type" class="form-select" required>
                <option value="Report" {{ $document->type == 'Report' ? 'selected' : '' }}>Report</option>
                <option value="Memo" {{ $document->type == 'Memo' ? 'selected' : '' }}>Memo</option>
                <option value="Policy" {{ $document->type == 'Policy' ? 'selected' : '' }}>Policy</option>
                <option value="Form" {{ $document->type == 'Form' ? 'selected' : '' }}>Form</option>
            </select>
        </div>

        <div class="mb-3">
            <label for="file" class="form-label">Replace File (optional)</label>
            <input type="file" name="file" id="file" class="form-control">
            @if ($document->file_path)
                <p class="mt-2">Current: <a href="{{ asset('storage/' . $document->file_path) }}" target="_blank">View File</a></p>
            @endif
        </div>

        <button type="submit" class="btn btn-primary">Update</button>
        <a href="{{ route('documents.index') }}" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<div class="mb-3">
    <label for="department" class="form-label">Department</label>
    <select name="department_id" class="form-control">
        @foreach ($departments as $department)
            <option value="{{ $department->id }}" {{ $user->department_id == $department->id ? 'selected' : '' }}>
                {{ $department->name }}
            </option>
        @endforeach
    </select>
</div>

<div class="mb-3">
    <label for="section" class="form-label">Section</label>
    <select name="section_id" class="form-control">
        @foreach ($sections as $section)
            <option value="{{ $section->id }}" {{ $user->section_id == $section->id ? 'selected' : '' }}>
                {{ $section->name }}
            </option>
        @endforeach
    </select>
</div>

@endsection
