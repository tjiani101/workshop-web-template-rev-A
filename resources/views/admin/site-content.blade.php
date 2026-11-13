@extends('layouts.app')

@section('title', 'Website content — MINEX Admin')

@section('content')
<section class="workspace-page admin-page"><div class="container"><div class="workspace-top"><div><p class="eyebrow"><span class="red-rule"></span>ADMIN / WEBSITE CONTENT</p><h1>Shape the public story.</h1><p>Edit published copy and contact details without changing application code.</p></div><a class="text-link" href="{{ route('admin.dashboard') }}">← Workspace</a></div>
    @if($errors->any())<div class="admin-error">{{ $errors->first() }}</div>@endif
    <form action="{{ route('admin.site-content.update') }}" method="POST" enctype="multipart/form-data" class="admin-content-grid">@csrf @method('PATCH')
        @foreach($sections as $sectionKey => $section)
            <section class="admin-card admin-content-card"><div class="admin-section-line"><span class="eyebrow">{{ $section['label'] }}</span><label class="admin-required-check"><input type="checkbox" name="published[{{ $sectionKey }}]" value="1" @checked(old('published.'.$sectionKey, $section['is_published']))> Published</label></div>
                @foreach($section['fields'] as $field => $label)
                    <label>{{ $label }}
                        @if(in_array($field, ['body', 'address', 'hours'], true) || str_contains($field, '_body'))
                            <textarea name="content[{{ $sectionKey }}][{{ $field }}]" rows="{{ $field === 'body' ? 4 : 2 }}" maxlength="2000">{{ old("content.{$sectionKey}.{$field}", $section['content'][$field] ?? '') }}</textarea>
                        @else
                            <input name="content[{{ $sectionKey }}][{{ $field }}]" value="{{ old("content.{$sectionKey}.{$field}", $section['content'][$field] ?? '') }}" maxlength="180" @required($sectionKey !== 'contact')>
                        @endif
                    </label>
                @endforeach
                @if($sectionKey === 'home_gallery')<div class="admin-gallery-uploads"><span class="field-help">Replace a photo with a private upload that becomes a public gallery asset. JPG/PNG/WebP, up to 5 MB.</span>@for($index = 0; $index < 3; $index++)<label>Replace photo {{ $index + 1 }}<input type="file" name="gallery_images[{{ $index }}]" accept="image/jpeg,image/png,image/webp">@if(!empty($section['content']['images'][$index]))<small>Current: {{ basename($section['content']['images'][$index]) }}</small>@endif</label>@endfor</div>@endif
            </section>
        @endforeach
        <div class="admin-content-submit"><button class="button button-red" type="submit">Save website content <span>↗</span></button></div>
    </form>
</div></section>
@endsection
