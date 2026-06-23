@extends('layouts.app')@section('content')<div class="card"><h1>{{ $item->title ?? $item->name }}</h1><p>{{ $item->description ?? $item->notes }}</p></div>@endsection
