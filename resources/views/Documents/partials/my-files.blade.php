@if($documents->isEmpty())
    <p class="text-muted">You haven't uploaded any documents yet.</p>
@else
    <table class="table table-bordered bg-white text-dark">
        <thead>
            <tr>
                <th>Title</th>
                <th>Tracking Code</th>
                <th>Uploaded At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($documents as $doc)
                <tr>
                    <td>{{ $doc->title }}</td>
                    <td>{{ $doc->code }}</td>
                    <td>{{ $doc->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        <a href="{{ route('documents.view', $doc->code) }}" class="btn btn-sm btn-outline-primary">View</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
