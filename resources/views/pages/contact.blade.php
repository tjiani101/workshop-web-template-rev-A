@extends('layouts.app')

@section('title', 'Contact MINEX Motorcycle Workshop')
@section('meta_description', 'Send a service question to MINEX motorcycle workshop.')

@section('content')
@php($contact = $content['contact'] ?? [])
<section class="contact-page section-pad"><div class="container contact-grid"><div class="contact-copy"><p class="eyebrow"><span class="red-rule"></span>MINEX / CONTACT</p><h1>Tell us about<br>your motorcycle.</h1><p>Ask a question or tell us what you have noticed. The MINEX team can help you find the right next step.</p><div class="contact-details">
    @if(!empty($contact['email']))<div><span class="eyebrow">EMAIL</span><a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a></div>@endif
    @if(!empty($contact['phone']))<div><span class="eyebrow">CALL</span><a href="tel:{{ $contact['phone'] }}">{{ $contact['phone'] }}</a></div>@endif
    @if(!empty($contact['whatsapp']))<div><span class="eyebrow">WHATSAPP</span><a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $contact['whatsapp']) }}">{{ $contact['whatsapp'] }}</a></div>@endif
    @if(!empty($contact['address']))<div><span class="eyebrow">WORKSHOP</span><p>{{ $contact['address'] }}</p></div>@endif
    @if(!empty($contact['hours']))<div><span class="eyebrow">HOURS</span><p>{{ $contact['hours'] }}</p></div>@endif
    @if(empty($contact['email']) && empty($contact['phone']) && empty($contact['whatsapp']) && empty($contact['address']))<div class="contact-config-note"><span class="eyebrow">WORKSHOP DETAILS</span><p>Contact channels and location will be published here once configured by MINEX.</p></div>@endif
</div></div>
<div class="contact-form-wrap"><div class="form-heading"><span class="eyebrow">SEND A MESSAGE</span><h2>We'll take it from here.</h2></div><form class="form-stack" action="{{ route('contact.send') }}" method="POST">@csrf
    <label>Your name<input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="120">@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
    <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required maxlength="254">@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
    <label>Subject <span class="optional-label">Optional</span><input type="text" name="subject" value="{{ old('subject', request('subject')) }}" maxlength="180"></label>
    <label>How can we help?<textarea name="message" rows="5" required minlength="10" maxlength="5000">{{ old('message') }}</textarea>@error('message')<small class="field-error">{{ $message }}</small>@enderror</label>
    <p class="privacy-note">Your message is stored for the workshop team to respond. Do not include payment or account passwords.</p>
    <button class="button button-red" type="submit">Send to MINEX <span aria-hidden="true">↗</span></button>
</form></div></div></section>
@endsection
