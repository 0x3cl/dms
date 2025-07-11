<table class="table table-bordered table-striped bg-white text-dark">
    <thead class="table-primary">
        <tr>
            <th>Title</th>
            <th>Tracking Code</th>
            <th>Uploaded By</th>
            <th>Uploaded At</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($documents as $doc)
        <tr>
            <td>{{ $doc->title }}</td>
            <td>{{ $doc->code }}</td>
            <td>{{ $doc->user->name ?? 'N/A' }}</td>
            <td>{{ $doc->created_at->format('M d, Y h:i A') }}</td>
            <td>
                <a href="{{ route('documents.view', $doc->code) }}" class="btn btn-sm btn-outline-primary">View</a>
            </td>
        </tr>
        @empty
        <tr>
            <td colspan="5" class="text-center">No documents found.</td>
        </tr>
        @endforelse
    </tbody>
</table>
