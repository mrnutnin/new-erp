@extends('layouts.app')

@section('sidebar')
    {{-- Shared navigation is intentional until the WMS/Purchasing menu split is complete. --}}
    @include('Wms::partials.sidebar')
@endsection
