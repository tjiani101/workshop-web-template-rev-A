<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\WebsiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteAdminController extends Controller
{
    private const CONTENT_SECTIONS = [
        'home_hero' => ['key' => 'home.hero', 'label' => 'Homepage hero', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'body' => 'Supporting copy']],
        'home_intro' => ['key' => 'home.intro', 'label' => 'Homepage introduction', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'body' => 'Supporting copy']],
        'home_principles' => ['key' => 'home.principles', 'label' => 'Homepage workshop principles', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'body' => 'Introduction', 'item_one_title' => 'Principle one title', 'item_one_body' => 'Principle one copy', 'item_two_title' => 'Principle two title', 'item_two_body' => 'Principle two copy', 'item_three_title' => 'Principle three title', 'item_three_body' => 'Principle three copy']],
        'home_process' => ['key' => 'home.process', 'label' => 'Homepage service process', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'step_one_title' => 'Step one title', 'step_one_body' => 'Step one copy', 'step_two_title' => 'Step two title', 'step_two_body' => 'Step two copy', 'step_three_title' => 'Step three title', 'step_three_body' => 'Step three copy']],
        'home_gallery' => ['key' => 'home.gallery', 'label' => 'Homepage gallery captions', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'body' => 'Supporting copy', 'caption_one' => 'Photo one caption', 'caption_two' => 'Photo two caption', 'caption_three' => 'Photo three caption']],
        'home_announcement' => ['key' => 'home.announcement', 'label' => 'Homepage announcement', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Announcement title', 'body' => 'Announcement copy']],
        'home_cta' => ['key' => 'home.cta', 'label' => 'Homepage call to action', 'fields' => ['eyebrow' => 'Eyebrow', 'title' => 'Headline', 'button' => 'Button label']],
        'about' => ['key' => 'about', 'label' => 'About page', 'fields' => ['title' => 'Headline', 'body' => 'Body copy']],
        'contact' => ['key' => 'contact', 'label' => 'Contact information', 'fields' => ['email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp', 'address' => 'Workshop address', 'hours' => 'Business hours']],
    ];

    public function content(): View
    {
        $records = WebsiteContent::query()->get()->keyBy('content_key');
        $sections = [];

        foreach (self::CONTENT_SECTIONS as $formKey => $definition) {
            $record = $records->get($definition['key']);
            $content = $record?->content ?? [];
            if ($formKey === 'home_principles') {
                foreach (['one', 'two', 'three'] as $index => $number) {
                    $content['item_'.$number.'_title'] = $content['items'][$index]['title'] ?? '';
                    $content['item_'.$number.'_body'] = $content['items'][$index]['body'] ?? '';
                }
            } elseif ($formKey === 'home_process') {
                foreach (['one', 'two', 'three'] as $index => $number) {
                    $content['step_'.$number.'_title'] = $content['steps'][$index]['title'] ?? '';
                    $content['step_'.$number.'_body'] = $content['steps'][$index]['body'] ?? '';
                }
            } elseif ($formKey === 'home_gallery') {
                foreach (['one', 'two', 'three'] as $index => $number) {
                    $content['caption_'.$number] = $content['captions'][$index] ?? '';
                }
            }
            $sections[$formKey] = [
                ...$definition,
                'content' => $content,
                'is_published' => $record?->is_published ?? ! in_array($formKey, ['home_principles', 'home_process', 'home_gallery', 'home_announcement'], true),
            ];
        }

        return view('admin.site-content', compact('sections'));
    }

    public function updateContent(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (self::CONTENT_SECTIONS as $formKey => $definition) {
            foreach ($definition['fields'] as $field => $label) {
                $required = in_array($formKey, ['home_principles', 'home_process', 'home_gallery', 'home_announcement'], true)
                    ? Rule::requiredIf($request->boolean("published.{$formKey}"))
                    : ($formKey === 'contact' ? 'nullable' : 'required');
                $rules["content.{$formKey}.{$field}"] = [$required, 'nullable', 'string', 'max:'.(str_contains($field, 'body') || $field === 'body' ? 2000 : 180)];
            }
            $rules["published.{$formKey}"] = ['nullable', 'boolean'];
        }
        $rules['gallery_images'] = ['nullable', 'array', 'max:3'];
        $rules['gallery_images.*'] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

        $validated = $request->validate($rules);

        foreach (self::CONTENT_SECTIONS as $formKey => $definition) {
            $record = WebsiteContent::query()->firstOrNew(['content_key' => $definition['key']]);
            $content = $validated['content'][$formKey] ?? [];
            if (isset($validated['content'][$formKey]) && $formKey === 'home_principles') {
                $content['items'] = collect(['one', 'two', 'three'])->map(fn ($number): array => ['title' => $content['item_'.$number.'_title'], 'body' => $content['item_'.$number.'_body']])->all();
                foreach (['one', 'two', 'three'] as $number) {
                    unset($content['item_'.$number.'_title'], $content['item_'.$number.'_body']);
                }
            } elseif (isset($validated['content'][$formKey]) && $formKey === 'home_process') {
                $content['steps'] = collect(['one', 'two', 'three'])->map(fn ($number): array => ['title' => $content['step_'.$number.'_title'], 'body' => $content['step_'.$number.'_body']])->all();
                foreach (['one', 'two', 'three'] as $number) {
                    unset($content['step_'.$number.'_title'], $content['step_'.$number.'_body']);
                }
            } elseif (isset($validated['content'][$formKey]) && $formKey === 'home_gallery') {
                $content['captions'] = [$content['caption_one'], $content['caption_two'], $content['caption_three']];
                unset($content['caption_one'], $content['caption_two'], $content['caption_three']);
                $images = $record->content['images'] ?? [];
                foreach ($request->file('gallery_images', []) as $index => $image) {
                    $images[$index] = $image->storePublicly('minex/gallery', 'public');
                }
                if ($images !== []) {
                    ksort($images);
                    $content['images'] = array_values($images);
                }
            }
            $record->content = array_replace($record->content ?? [], $content);
            $record->is_published = $request->boolean("published.{$formKey}");
            $record->save();
        }

        return back()->with('status', 'Website content and publication settings saved.');
    }

    public function messages(): View
    {
        return view('admin.messages', [
            'messages' => ContactMessage::query()->latest()->paginate(25),
        ]);
    }

    public function updateMessage(Request $request, ContactMessage $message): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:new,read,replied,closed'],
        ]);

        $message->update([
            'status' => $validated['status'],
            'handled_at' => in_array($validated['status'], ['replied', 'closed'], true) ? now() : null,
        ]);

        return back()->with('status', 'Contact message status updated.');
    }
}
