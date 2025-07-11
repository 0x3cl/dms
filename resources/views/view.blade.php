@extends('layouts.app')

@section('content')
<div class="container py-5 text-center">
    <h2>Track Document</h2>

    <form action="{{ route('documents.viewByCode') }}" method="GET" class="mt-4">
        <div class="input-group w-50 mx-auto">
            <input type="text" name="code" class="form-control" placeholder="Enter Tracking Code" required>
            <button class="btn btn-primary">Track</button>
        </div>
    </form>
</div>
@endsection
