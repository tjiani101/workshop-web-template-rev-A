@extends('layouts.app')

@section('title', 'Catalog administration — MINEX')

@section('content')
<section class="workspace-page admin-page"><div class="container">
    <div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>OWNER / ADMIN</p><h1>Catalog control.</h1><p>Manage what riders can see and configure for new service visits.</p></div><a class="text-link" href="{{ route('admin.dashboard') }}">← Workspace overview</a></div>
    @if($errors->any())<div class="admin-error">Please review the highlighted fields. {{ $errors->first() }}</div>@endif
    <div class="admin-count-grid"><article><span>Service areas</span><strong>{{ $categories }}</strong><a href="{{ route('admin.categories.index') }}">Manage categories ↗</a></article><article><span>Services</span><strong>{{ $services }}</strong><a href="{{ route('admin.services.index') }}">Manage services ↗</a></article><article><span>Maintenance plans</span><strong>{{ $packages }}</strong><a href="{{ route('admin.packages.index') }}">Open package builder ↗</a></article><article><span>Part records</span><strong>{{ $parts }}</strong><a href="{{ route('admin.parts.index') }}">Manage parts ↗</a></article><article><span>Break-in programs</span><strong><a href="{{ route('admin.engine-break-ins.index') }}">Open program desk ↗</a></strong></article></div>
    <div class="admin-subnav"><a href="{{ route('admin.categories.index') }}">Categories</a><a href="{{ route('admin.services.index') }}">Services</a><a href="{{ route('admin.packages.index') }}">Package builder</a><a href="{{ route('admin.parts.index') }}">Internal parts &amp; variants</a><a href="{{ route('admin.labor-pricing.index') }}">Labor rates &amp; billing</a><a href="{{ route('admin.engine-break-ins.index') }}">Engine Break-In programs</a></div>
    <div class="admin-note"><span class="eyebrow">PRICE INTEGRITY</span><p>Enter prices in whole Rupiah. Leave a value empty until MINEX has approved it. Compatibility records start unverified and are not presented as confirmed fitment.</p></div>
</div></section>
@endsection
