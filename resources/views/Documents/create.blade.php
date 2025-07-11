@extends('layouts.app')

@section('content')
<div class="container py-5">
    <h2>Upload New Document</h2>

    <form method="POST" action="{{ route('documents.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label for="title" class="form-label">Document Title</label>
            <input type="text" name="title" class="form-control" required>
        </div>
        <div class="mb-3">
    <label for="file" class="form-label">Choose File</label>
    <input type="file" name="file" class="form-control" required>
</div>
        <div class="mb-3">
    <label for="type" class="form-label">Document Type</label>
    <select name="type" class="form-select">
        <option value="">-- Select Type --</option>
        <option value="Confidential">COnfidential</option>
        <option value="Administrative Order">Administrative Order</option>
        <option value="Special Order">Special Order</option>
        <option value="Memo">Memo</option>
        <option value="TRACE docs">TRACE docs</option>
        <option value="Contract of Service">Contract of Service</option>
        <option value="Memorandum of Understanding">Memorandum of Understanding</option>
        <option value="JRU">JRU</option>
        <option value="IPCR/DPCR">IPCR/DPCR</option>
    </select>
</div>

        <button class="btn btn-success">Upload</button>
    </form>
</div>
@endsection
