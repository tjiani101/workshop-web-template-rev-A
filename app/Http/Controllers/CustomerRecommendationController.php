<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\InspectionMedia;
use App\Services\AdditionalWorkDecisionService;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerRecommendationController extends Controller
{
    public function decide(Request $request, string $reference, int $recommendation, AdditionalWorkDecisionService $decisions, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($booking->user_id) {
            abort_unless((int) $request->user()?->id === (int) $booking->user_id, 404);
        }
        if ($request->user() && ! $request->user()->hasRole('customer')) {
            abort(403);
        }
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'declined'])], 'decision_note' => ['nullable', 'string', 'max:1000']]);
        $record = $decisions->decide($booking, $recommendation, $data['decision'], $request->user(), $data['decision_note'] ?? null);
        $audit->record($request, 'recommendation.'.$data['decision'], $record, ['booking_id' => $booking->id, 'decision_note' => $data['decision_note'] ?? null]);

        $mechanic = $record->mechanicUser()->first();
        if ($mechanic) {
            $notifications->send($mechanic, 'recommendation_decided', 'Customer decision received', $record->title.' was '.$data['decision'].'.', route('mechanic.dashboard'), ['recommendation_id' => $record->id]);
        }
        $booking->refresh();

        return $booking->user_id
            ? redirect()->route('portal.bookings.show', $booking->id)->with('status', 'Your decision has been saved. The balance now includes any accepted work.')
            : redirect()->route('bookings.received', $booking->reference)->with('status', 'Your decision has been saved. The balance now includes any accepted work.');
    }

    public function decideAll(Request $request, string $reference, AdditionalWorkDecisionService $decisions, NotificationService $notifications, AuditLogger $audit): RedirectResponse
    {
        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($booking->user_id) {
            abort_unless((int) $request->user()?->id === (int) $booking->user_id, 404);
        }
        if ($request->user() && ! $request->user()->hasRole('customer')) {
            abort(403);
        }
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'declined'])]]);
        $recommendations = $booking->workOrder()->firstOrFail()->recommendations()->where('customer_decision', 'pending')->get();
        abort_if($recommendations->isEmpty(), 409);

        DB::transaction(function () use ($request, $booking, $recommendations, $data, $decisions, $notifications, $audit): void {
            foreach ($recommendations as $recommendation) {
                $updated = $decisions->decide($booking, $recommendation->id, $data['decision'], $request->user());
                $audit->record($request, 'recommendation.'.$data['decision'], $updated, ['booking_id' => $booking->id, 'bulk_decision' => true]);
                $mechanic = $updated->mechanicUser()->first();
                if ($mechanic) {
                    $notifications->send($mechanic, 'recommendation_decided', 'Customer decision received', $updated->title.' was '.$data['decision'].'.', route('mechanic.dashboard'), ['recommendation_id' => $updated->id]);
                }
            }
        });

        $booking->refresh();

        return $booking->user_id
            ? redirect()->route('portal.bookings.show', $booking->id)->with('status', 'Your decisions have been saved. The balance now includes any accepted work.')
            : redirect()->route('bookings.received', $booking->reference)->with('status', 'Your decisions have been saved. The balance now includes any accepted work.');
    }

    public function privateMedia(Request $request, string $reference, int $media)
    {
        $booking = Booking::query()->where('reference', $reference)->firstOrFail();
        if ($booking->user_id) {
            abort_unless((int) $request->user()?->id === (int) $booking->user_id, 404);
        }
        if ($request->user() && ! $request->user()->hasRole('customer')) {
            abort(403);
        }
        $record = InspectionMedia::query()->with('finding.inspection.workOrder')->findOrFail($media);
        abort_unless((int) $record->finding->inspection->work_order_id === (int) $booking->workOrder()->value('id'), 404);
        abort_unless(Storage::disk($record->storage_disk)->exists($record->storage_path), 404);

        return Storage::disk($record->storage_disk)->response($record->storage_path, $record->original_name, ['Content-Type' => $record->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
