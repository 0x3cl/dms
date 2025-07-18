<div class="table-responsive">
    <table class="table table-white table-hover table-bordered shadow-sm rounded">
        <thead>
            <tr>
                <th>Title</th>
                <th>Tracking Code</th>
                <th>Uploader</th>
                <th>Date Uploaded</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($receivedDocuments as $doc)
                <tr>
                    <td>{{ $doc->title }}</td>
                    <td>{{ $doc->code }}</td>
                    <td>{{ $doc->user->name ?? 'N/A' }}</td>
                    <td>{{ $doc->created_at->format('Y-m-d') }}</td>
                    <td>
                        @if ($doc->status === 'ended')
                            <span class="badge bg-danger">Ended</span>
                        @else
                            <span class="badge bg-success">Active</span>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('documents.view', $doc->code) }}" class="btn btn-sm btn-outline-info">
                            View Details
                        </a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">You have not received, viewed, or downloaded any documents yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
