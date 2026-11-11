@extends('layouts.app')

@section('content')
<div class="container">
    {{-- Success & Status Messages --}}
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <h1 class="mb-4">Documents</h1>

    <a href="{{ route('documents.create') }}" class="btn btn-primary mb-3">Upload New Document</a>

    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th>Title</th>
                <th>Document Type</th>
                <th>File Type</th>
                <th>File</th>
                <th>Actions / Log Activity</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td>{{ $document->title }}</td>
                    <td>{{ $document->type ?? 'N/A' }}</td>
                    <td>
                        @if ($document->file_path)
                            {{ pathinfo($document->file_path, PATHINFO_EXTENSION) }}
                        @else
                            N/A
                        @endif
                    </td>
                    <td>
                        @if ($document->file_path)
                            <a href="{{ asset('storage/' . $document->file_path) }}" target="_blank">View File</a>
                        @else
                            No file
                        @endif
                    </td>
                    <td style="min-width: 300px;">
                        <a href="{{ route('documents.edit', $document->id) }}" class="btn btn-sm btn-warning mb-2">Edit</a>

                        <form action="{{ route('documents.destroy', $document->id) }}" method="POST" class="d-inline-block mb-2" onsubmit="return confirm('Delete this document?')">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-danger">Delete</button>
                        </form>

                        {{-- Log Activity Form --}}
                        <form action="{{ route('document.log') }}" method="POST" class="mt-2">
                            @csrf
                            <input type="hidden" name="document_id" value="{{ $document->id }}">
                            <input type="text" name="department" placeholder="Department" required class="form-control mb-1" />
                            <input type="text" name="section" placeholder="Section" required class="form-control mb-1" />
                            <button type="submit" class="btn btn-sm btn-success w-100">Log Activity</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5">No documents found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
