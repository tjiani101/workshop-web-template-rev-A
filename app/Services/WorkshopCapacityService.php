<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\MechanicProfile;
use App\Models\WorkshopClosure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkshopCapacityService
{
    /** @param list<int> $mechanicProfileIds */
    public function reserve(Booking $booking, Carbon $startsAt, array $mechanicProfileIds, int $durationMinutes, bool $allowSpecializationOverride, ?string $overrideReason, int $actorId, ?string $notes = null): Appointment
    {
        if ($mechanicProfileIds === [] || $durationMinutes < 1 || $durationMinutes > 1440) {
            throw ValidationException::withMessages(['appointment' => 'Choose at least one mechanic and a valid job duration.']);
        }
        if ($allowSpecializationOverride && blank($overrideReason)) {
            throw ValidationException::withMessages(['override_reason' => 'Add a reason when assigning work outside a mechanic specialization.']);
        }

        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        return DB::transaction(function () use ($booking, $startsAt, $endsAt, $mechanicProfileIds, $durationMinutes, $allowSpecializationOverride, $overrideReason, $actorId, $notes): Appointment {
            $setting = BusinessSetting::query()->firstOrCreate(['setting_key' => 'operating_hours'], ['setting_value' => ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]]);
            $settings = BusinessSetting::query()->lockForUpdate()->findOrFail($setting->id)->setting_value;
            $closure = WorkshopClosure::query()->whereDate('closure_date', $startsAt->toDateString())->lockForUpdate()->first();
            $workdays = array_map('intval', $settings['workdays'] ?? []);
            $openAt = $startsAt->copy()->setTimeFromTimeString($closure?->opens_at ? substr($closure->opens_at, 0, 5) : ($settings['open'] ?? '09:00'));
            $closeAt = $startsAt->copy()->setTimeFromTimeString($closure?->closes_at ? substr($closure->closes_at, 0, 5) : ($settings['close'] ?? '21:00'));
            $lockedBooking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $lockedBooking->loadMissing('items');
            $isFullDay = $lockedBooking->requiresFullDayReservation();
            if ($isFullDay) {
                if (! $startsAt->equalTo($openAt)) {
                    throw ValidationException::withMessages(['scheduled_start_at' => 'A full-day service must start at workshop opening time.']);
                }
                $endsAt = $closeAt->copy();
            }
            if (($closure?->is_closed ?? false) || (! $closure && ! in_array((int) $startsAt->isoWeekday(), $workdays, true)) || $startsAt->lt($openAt) || $endsAt->gt($closeAt) || $startsAt->toDateString() !== $endsAt->toDateString()) {
                throw ValidationException::withMessages(['scheduled_start_at' => 'The appointment must fit within the workshop opening hours on an operating day.']);
            }
            foreach ($isFullDay ? [] : ($settings['breaks'] ?? []) as $break) {
                if (! empty($break['start']) && ! empty($break['end'])) {
                    $breakStart = $startsAt->copy()->setTimeFromTimeString($break['start']);
                    $breakEnd = $startsAt->copy()->setTimeFromTimeString($break['end']);
                    if ($startsAt->lt($breakEnd) && $endsAt->gt($breakStart)) {
                        throw ValidationException::withMessages(['scheduled_start_at' => 'The appointment overlaps a configured workshop break.']);
                    }
                }
            }
            $profiles = MechanicProfile::query()->with(['specializations', 'user'])->whereIn('id', array_unique(array_map('intval', $mechanicProfileIds)))->where('is_active', true)->lockForUpdate()->get();
            if ($profiles->count() !== count(array_unique($mechanicProfileIds))) {
                throw ValidationException::withMessages(['mechanic_profile_ids' => 'One or more selected mechanics are inactive or unavailable.']);
            }

            $previous = Appointment::query()->where('booking_id', $lockedBooking->id)->whereIn('status', ['reserved', 'confirmed', 'scheduled'])->lockForUpdate()->get();
            foreach ($previous as $old) {
                $old->update(['status' => 'cancelled', 'reserved_until' => null]);
                AppointmentHistory::create(['appointment_id' => $old->id, 'event_type' => 'rescheduled', 'previous_start_at' => $old->scheduled_start_at, 'previous_end_at' => $old->scheduled_end_at, 'new_start_at' => $startsAt, 'new_end_at' => $endsAt, 'note' => 'Replaced by a new appointment reservation.', 'changed_by' => $actorId]);
            }

            $bayCapacity = max(1, (int) ($settings['bay_capacity'] ?? 1));
            $occupiedBays = Appointment::query()->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])
                ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
                ->where(function ($query) use ($startsAt, $endsAt): void {
                    $query->whereIn('status', ['checked_in', 'inspection', 'waiting_customer_approval', 'in_progress', 'waiting_parts', 'overnight', 'completed'])
                        ->orWhere(fn ($overlap) => $overlap->where('scheduled_start_at', '<', $endsAt)->where('scheduled_end_at', '>', $startsAt));
                })
                ->count();
            if ($occupiedBays >= $bayCapacity) {
                throw ValidationException::withMessages(['scheduled_start_at' => 'The workshop has no open service bay for this appointment window.']);
            }

            $requiredCategories = $lockedBooking->items()->get()->flatMap(function ($item): array {
                $snapshot = $item->catalog_snapshot ?? [];
                $ids = [];
                if (($snapshot['service']['category_id'] ?? null) !== null) {
                    $ids[] = (int) $snapshot['service']['category_id'];
                }
                if (($snapshot['package']['category_id'] ?? null) !== null) {
                    $ids[] = (int) $snapshot['package']['category_id'];
                }
                foreach (($snapshot['items'] ?? []) as $part) {
                    if (($part['selected'] ?? false) && isset($part['service']['category_id'])) {
                        $ids[] = (int) $part['service']['category_id'];
                    }
                }

                return $ids;
            })->unique()->values()->all();

            $minutesEach = $isFullDay ? null : (int) ceil($durationMinutes / $profiles->count());
            foreach ($profiles as $profile) {
                if ($profile->leaveDays()->whereDate('leave_date', $startsAt->toDateString())->exists()) {
                    throw ValidationException::withMessages(['mechanic_profile_ids' => $profile->user->name.' is on leave for the selected day.']);
                }
                $overlaps = Appointment::query()->whereNotIn('status', ['cancelled', 'no_show'])
                    ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
                    ->where('scheduled_start_at', '<', $endsAt)->where('scheduled_end_at', '>', $startsAt)
                    ->whereHas('mechanicAssignments', fn ($query) => $query->where('mechanic_profile_id', $profile->id))
                    ->exists();
                if ($overlaps) {
                    throw ValidationException::withMessages(['scheduled_start_at' => $profile->user->name.' already has an overlapping appointment.']);
                }
                $usedMinutes = DB::table('mechanic_assignments')->join('appointments', 'appointments.id', '=', 'mechanic_assignments.appointment_id')
                    ->where('mechanic_assignments.mechanic_profile_id', $profile->id)
                    ->whereDate('appointments.scheduled_start_at', $startsAt->toDateString())
                    ->whereNotIn('appointments.status', ['cancelled', 'no_show'])
                    ->where(fn ($query) => $query->where('appointments.status', '!=', 'reserved')->orWhereNull('appointments.reserved_until')->orWhere('appointments.reserved_until', '>', now()))
                    ->sum('mechanic_assignments.assigned_minutes');
                $assignedMinutes = $isFullDay ? $profile->daily_capacity_minutes : $minutesEach;
                if ($usedMinutes + $assignedMinutes > $profile->daily_capacity_minutes) {
                    throw ValidationException::withMessages(['mechanic_profile_ids' => $profile->user->name.' does not have enough daily capacity for this job.']);
                }
                $covered = $profile->specializations->pluck('id')->map(fn ($id) => (int) $id)->intersect($requiredCategories)->count() === count($requiredCategories);
                if (! $covered && ! $allowSpecializationOverride) {
                    throw ValidationException::withMessages(['mechanic_profile_ids' => $profile->user->name.' is not qualified for every service area in this booking.']);
                }
            }

            $temporaryHold = in_array($lockedBooking->status, ['pending_approval', 'awaiting_payment'], true);
            $appointmentStatus = $temporaryHold ? 'reserved' : 'confirmed';
            $appointment = Appointment::create(['booking_id' => $lockedBooking->id, 'scheduled_start_at' => $startsAt, 'scheduled_end_at' => $endsAt, 'status' => $appointmentStatus, 'reserved_until' => $temporaryHold ? now()->addHours(24) : null, 'notes' => $notes, 'created_by' => $actorId]);
            foreach ($profiles as $profile) {
                $covered = $profile->specializations->pluck('id')->map(fn ($id) => (int) $id)->intersect($requiredCategories)->count() === count($requiredCategories);
                $appointment->mechanicAssignments()->create(['mechanic_profile_id' => $profile->id, 'assigned_minutes' => $isFullDay ? $profile->daily_capacity_minutes : $minutesEach, 'specialization_override' => ! $covered, 'override_reason' => ! $covered ? trim((string) $overrideReason) : null, 'assigned_by' => $actorId]);
            }
            AppointmentHistory::create(['appointment_id' => $appointment->id, 'event_type' => $temporaryHold ? 'reserved' : 'rescheduled', 'new_start_at' => $startsAt, 'new_end_at' => $endsAt, 'note' => $notes, 'changed_by' => $actorId]);

            return $appointment;
        });
    }
}
