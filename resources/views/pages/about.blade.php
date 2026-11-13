@extends('layouts.app')

@section('title', 'About MINEX — Motorcycle care with clarity')
@section('meta_description', 'Learn how MINEX approaches careful motorcycle maintenance and transparent service.')

@section('content')
@php($about = $content['about'] ?? [])
<section class="about-hero"><div class="about-photo"><img src="{{ asset('images/service-mechanic.jpg') }}" alt="Technician's hands working on motorcycle engine components" fetchpriority="high"></div><div class="about-shade"></div><div class="container about-hero-copy"><p class="eyebrow red-eyebrow"><span class="red-rule"></span>ABOUT MINEX</p><h1>Good maintenance<br>starts with a clear<br>conversation.</h1><p>Professional motorcycle service for the miles you ride every day.</p></div></section>
<section class="about-statement section-pad"><div class="container about-statement-grid"><span class="eyebrow">OUR APPROACH / 001</span><div><h2>{{ $about['title'] ?? 'Good maintenance starts with a clear conversation.' }}</h2><p>{{ $about['body'] ?? 'We explain what we find, show the work we recommend, and ask before we add anything to your service.' }}</p><a class="arrow-link" href="{{ route('services.index') }}">Explore our services <span aria-hidden="true">↗</span></a></div></div></section>
<section class="values-section section-pad section-graphite"><div class="container"><div class="section-heading"><p class="eyebrow"><span class="red-rule"></span>WHAT GUIDES THE WORK</p><h2>Precision, without the theatre.</h2></div><div class="values-grid">@foreach(($about['values'] ?? []) as $index => $value)<article><span>{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $value['title'] }}</h3><p>{{ $value['body'] }}</p></article>@endforeach</div></div></section>
<section class="about-image-band"><img src="{{ asset('images/hero-workshop.jpg') }}" alt="Motorcycle service work in progress" loading="lazy"><div><span class="eyebrow red-eyebrow">BUILT AROUND EVERYDAY RIDING</span><p>From a routine visit to a deeper inspection, good service begins with knowing why the work matters.</p></div></section>
<section class="simple-cta"><div class="container simple-cta-inner"><div><span class="eyebrow">YOUR MOTORCYCLE, YOUR DECISION</span><h2>Start with a conversation.</h2></div><a class="button button-red" href="{{ route('contact') }}">Get in touch <span aria-hidden="true">↗</span></a></div></section>
@endsection
