@if($documents->isEmpty())
    <p class="text-muted">You haven't uploaded any documents yet.</p>
@else
    <div class="table-responsive">
        <table class="table table-light table-hover table-bordered shadow-sm rounded">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Tracking Code</th>
                    <th>Uploaded At</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($documents as $doc)
                    <tr>
                        <td>{{ $doc->title }}</td>
                        <td>{{ $doc->code }}</td>
                        <td>{{ $doc->created_at->format('Y-m-d H:i') }}</td>
                        <td>
                            {{-- Display a status badge based on the 'status' column --}}
                            @if ($doc->status === 'ended')
                                <span class="badge bg-danger">Ended</span>
                            @else
                                <span class="badge bg-success">Active</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <a href="{{ route('documents.view', $doc->code) }}" class="btn btn-sm btn-outline-info">
                                    View
                                </a>

                                {{-- Show End button only if the document's status is NOT 'ended' --}}
                                @if($doc->status !== 'ended')
                                    <form action="{{ route('documents.end', $doc->id) }}" method="POST" class="d-inline">
                                        @csrf
                                        @method('PATCH') {{-- This is important for the PATCH route --}}
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Are you sure you want to mark this document as ended?')">
                                            End
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
