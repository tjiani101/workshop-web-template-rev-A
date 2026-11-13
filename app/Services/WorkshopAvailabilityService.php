<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BusinessSetting;
use App\Models\MechanicProfile;
use App\Models\WorkshopClosure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkshopAvailabilityService
{
    /** @param list<int> $requiredCategoryIds
     * @return list<string> local date-time values suitable for datetime-local inputs
     */
    public function availableSlots(Carbon $date, int $durationMinutes, array $requiredCategoryIds = [], int $intervalMinutes = 30): array
    {
        if ($durationMinutes < 1 || $durationMinutes > 1440 || $intervalMinutes < 5 || $intervalMinutes > 120) {
            return [];
        }

        $settings = BusinessSetting::value('operating_hours', [
            'open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1,
        ]);
        $window = $this->scheduleWindow($date, $settings);
        if (! $window) {
            return [];
        }

        $opensAt = $window['opens_at'];
        $closesAt = $window['closes_at'];

        $bayAppointments = Appointment::query()->with('mechanicAssignments')
            ->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])
            ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
            ->where('scheduled_start_at', '<', $closesAt)->where('scheduled_end_at', '>', $opensAt)
            ->get();
        $scheduleAppointments = Appointment::query()->with('mechanicAssignments')->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
            ->whereDate('scheduled_start_at', $date->toDateString())->get();
        $dailyUsage = DB::table('mechanic_assignments')->join('appointments', 'appointments.id', '=', 'mechanic_assignments.appointment_id')
            ->whereDate('appointments.scheduled_start_at', $date->toDateString())
            ->whereNotIn('appointments.status', ['cancelled', 'no_show'])
            ->where(fn ($query) => $query->where('appointments.status', '!=', 'reserved')->orWhereNull('appointments.reserved_until')->orWhere('appointments.reserved_until', '>', now()))
            ->select('mechanic_assignments.mechanic_profile_id')->selectRaw('SUM(mechanic_assignments.assigned_minutes) as used_minutes')
            ->groupBy('mechanic_assignments.mechanic_profile_id')->pluck('used_minutes', 'mechanic_profile_id');
        $profiles = MechanicProfile::query()->with(['specializations', 'leaveDays' => fn ($query) => $query->whereDate('leave_date', $date->toDateString())])
            ->where('is_active', true)->orderBy('id')->get()
            ->reject(fn (MechanicProfile $profile): bool => $profile->leaveDays->isNotEmpty())
            ->map(function (MechanicProfile $profile) use ($dailyUsage, $scheduleAppointments): array {
                $profileId = (int) $profile->id;
                $specializations = $profile->specializations->pluck('id')->map(fn ($id): int => (int) $id)->all();

                return [
                    'id' => $profileId,
                    'capacity' => (int) $profile->daily_capacity_minutes,
                    'used' => (int) ($dailyUsage[$profileId] ?? 0),
                    'specializations' => $specializations,
                    'appointments' => $scheduleAppointments->filter(fn (Appointment $appointment): bool => $appointment->mechanicAssignments->contains('mechanic_profile_id', $profileId)),
                ];
            })->values();

        if ($profiles->isEmpty()) {
            return [];
        }

        $requiredCategoryIds = array_values(array_unique(array_map('intval', $requiredCategoryIds)));
        $bayCapacity = max(1, (int) ($settings['bay_capacity'] ?? 1));
        $slots = [];
        for ($startsAt = $opensAt->copy(); $startsAt->copy()->addMinutes($durationMinutes)->lte($closesAt); $startsAt->addMinutes($intervalMinutes)) {
            $endsAt = $startsAt->copy()->addMinutes($durationMinutes);
            if ($startsAt->lt(now())) {
                continue;
            }

            $overlapsBreak = collect($settings['breaks'] ?? [])->contains(function (array $break) use ($date, $startsAt, $endsAt): bool {
                if (empty($break['start']) || empty($break['end'])) {
                    return false;
                }
                $breakStart = $date->copy()->setTimeFromTimeString($break['start']);
                $breakEnd = $date->copy()->setTimeFromTimeString($break['end']);

                return $startsAt->lt($breakEnd) && $endsAt->gt($breakStart);
            });
            if ($overlapsBreak) {
                continue;
            }

            $occupiedBays = $bayAppointments->filter(function (Appointment $appointment) use ($startsAt, $endsAt): bool {
                return $appointment->scheduled_start_at?->lt($endsAt) && $appointment->scheduled_end_at?->gt($startsAt);
            })->count();
            if ($occupiedBays >= $bayCapacity) {
                continue;
            }

            $availableProfiles = $profiles->filter(function (array $profile) use ($startsAt, $endsAt): bool {
                if ($profile['appointments']->contains(fn (Appointment $appointment): bool => ! in_array($appointment->status, ['cancelled', 'no_show'], true)
                    && $appointment->scheduled_start_at?->lt($endsAt) && $appointment->scheduled_end_at?->gt($startsAt))) {
                    return false;
                }

                return $profile['capacity'] - $profile['used'] >= 1;
            })->values();

            if ($this->hasQualifiedTeam($availableProfiles, $requiredCategoryIds, $durationMinutes)) {
                $slots[] = $startsAt->format('Y-m-d\TH:i');
            }
        }

        return $slots;
    }

    /** @param list<int> $requiredCategoryIds
     * @return list<string> A full-day service has one arrival candidate: workshop opening time.
     */
    public function availableFullDaySlots(Carbon $date, array $requiredCategoryIds = []): array
    {
        $settings = BusinessSetting::value('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]);
        $window = $this->scheduleWindow($date, $settings);
        if (! $window || $date->toDateString() < today()->toDateString() || $window['opens_at']->lt(now())) {
            return [];
        }

        $occupiedBays = Appointment::query()->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])
            ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
            ->where('scheduled_start_at', '<', $window['closes_at'])->where('scheduled_end_at', '>', $window['opens_at'])
            ->count();
        if ($occupiedBays >= max(1, (int) ($settings['bay_capacity'] ?? 1))) {
            return [];
        }

        $appointments = Appointment::query()->with('mechanicAssignments')
            ->whereDate('scheduled_start_at', $date->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
            ->get();
        $usedMinutes = DB::table('mechanic_assignments')->join('appointments', 'appointments.id', '=', 'mechanic_assignments.appointment_id')
            ->whereDate('appointments.scheduled_start_at', $date->toDateString())
            ->whereNotIn('appointments.status', ['cancelled', 'no_show'])
            ->where(fn ($query) => $query->where('appointments.status', '!=', 'reserved')->orWhereNull('appointments.reserved_until')->orWhere('appointments.reserved_until', '>', now()))
            ->selectRaw('mechanic_assignments.mechanic_profile_id, SUM(mechanic_assignments.assigned_minutes) as used_minutes')
            ->groupBy('mechanic_assignments.mechanic_profile_id')->pluck('used_minutes', 'mechanic_profile_id');
        $profiles = MechanicProfile::query()->with(['specializations', 'leaveDays' => fn ($query) => $query->whereDate('leave_date', $date->toDateString())])
            ->where('is_active', true)->get()
            ->reject(fn (MechanicProfile $profile): bool => $profile->leaveDays->isNotEmpty())
            ->filter(function (MechanicProfile $profile) use ($appointments, $window, $usedMinutes): bool {
                $used = (int) ($usedMinutes[$profile->id] ?? 0);
                if ($used > 0) {
                    return false;
                }

                return ! $appointments->contains(fn (Appointment $appointment): bool => $appointment->mechanicAssignments->contains('mechanic_profile_id', $profile->id)
                    && $appointment->scheduled_start_at?->lt($window['closes_at']) && $appointment->scheduled_end_at?->gt($window['opens_at']));
            })->values();

        $requiredCategoryIds = array_values(array_unique(array_map('intval', $requiredCategoryIds)));
        $qualified = $profiles->contains(function (MechanicProfile $profile) use ($requiredCategoryIds): bool {
            $specializations = $profile->specializations->pluck('id')->map(fn ($id): int => (int) $id)->all();

            return array_diff($requiredCategoryIds, $specializations) === [];
        });

        return $qualified ? [$window['opens_at']->format('Y-m-d\\TH:i')] : [];
    }

    /** @param list<int> $requiredCategoryIds @return array{date:string,start_at:string,end_at:string,duration_minutes:int}|null */
    public function recommendFullDay(Carbon $from, array $requiredCategoryIds = [], int $days = 60): ?array
    {
        for ($offset = 0; $offset <= $days; $offset++) {
            $date = $from->copy()->addDays($offset);
            $slots = $this->availableFullDaySlots($date, $requiredCategoryIds);
            if ($slots !== []) {
                $window = $this->scheduleWindow($date, BusinessSetting::value('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]));

                return [
                    'date' => $date->toDateString(),
                    'start_at' => $slots[0],
                    'end_at' => $window['closes_at']->format('Y-m-d\\TH:i'),
                    'duration_minutes' => (int) $window['opens_at']->diffInMinutes($window['closes_at']),
                ];
            }
        }

        return null;
    }

    /** @param list<int> $requiredCategoryIds @return array{day_status:string,duration_minutes:int,slots:list<array{start_at:string,status:string}>} */
    public function fullDaySlotMap(Carbon $date, array $requiredCategoryIds = []): array
    {
        $settings = BusinessSetting::value('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]);
        $window = $this->scheduleWindow($date, $settings);
        if (! $window) {
            return ['day_status' => 'closed', 'duration_minutes' => 0, 'slots' => []];
        }

        $startAt = $window['opens_at']->format('Y-m-d\\TH:i');
        $available = in_array($startAt, $this->availableFullDaySlots($date, $requiredCategoryIds), true);
        $status = $available ? (max(1, (int) ($settings['bay_capacity'] ?? 1)) === 1 ? 'limited' : 'available') : 'full';

        return [
            'day_status' => 'open',
            'duration_minutes' => (int) $window['opens_at']->diffInMinutes($window['closes_at']),
            'slots' => [['start_at' => $startAt, 'status' => $status]],
        ];
    }

    /** @param list<int> $requiredCategoryIds @return array{day_status:string,duration_minutes:int,slots:list<array{start_at:string,status:string}>} */
    public function slotMap(Carbon $date, int $durationMinutes, array $requiredCategoryIds = [], int $intervalMinutes = 30): array
    {
        $settings = BusinessSetting::value('operating_hours', ['open' => '09:00', 'close' => '21:00', 'workdays' => [1, 2, 3, 4, 5, 6], 'breaks' => [], 'bay_capacity' => 1]);
        $window = $this->scheduleWindow($date, $settings);
        if (! $window) {
            return ['day_status' => 'closed', 'duration_minutes' => $durationMinutes, 'slots' => []];
        }

        $opensAt = $window['opens_at'];
        $closesAt = $window['closes_at'];

        $available = array_fill_keys($this->availableSlots($date, $durationMinutes, $requiredCategoryIds, $intervalMinutes), true);
        $reservations = Appointment::query()->whereDate('scheduled_start_at', $date->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show', 'handed_over'])
            ->where(fn ($query) => $query->where('status', '!=', 'reserved')->orWhereNull('reserved_until')->orWhere('reserved_until', '>', now()))
            ->where('scheduled_start_at', '<', $closesAt)->where('scheduled_end_at', '>', $opensAt)
            ->get(['status', 'scheduled_start_at', 'scheduled_end_at', 'reserved_until']);
        $settingsHasLimitedCapacity = max(1, (int) ($settings['bay_capacity'] ?? 1)) === 1
            || MechanicProfile::query()->where('is_active', true)->count() === 1;
        $slots = [];
        for ($startsAt = $opensAt->copy(); $startsAt->lt($closesAt); $startsAt->addMinutes($intervalMinutes)) {
            $endsAt = $startsAt->copy()->addMinutes($durationMinutes);
            $time = $startsAt->format('Y-m-d\\TH:i');
            $overlapsBreak = collect($settings['breaks'] ?? [])->contains(function (array $break) use ($date, $startsAt, $endsAt): bool {
                if (empty($break['start']) || empty($break['end'])) {
                    return false;
                }
                $breakStart = $date->copy()->setTimeFromTimeString($break['start']);
                $breakEnd = $date->copy()->setTimeFromTimeString($break['end']);

                return $startsAt->lt($breakEnd) && $endsAt->gt($breakStart);
            });
            $status = 'full';
            if ($overlapsBreak) {
                $status = 'break';
            } elseif ($endsAt->gt($closesAt)) {
                $status = 'closed';
            } elseif (isset($available[$time])) {
                $status = $settingsHasLimitedCapacity ? 'limited' : 'available';
            } elseif ($reservations->contains(fn (Appointment $appointment): bool => $appointment->status === 'reserved'
                && (! $appointment->reserved_until || $appointment->reserved_until->isFuture())
                && $appointment->scheduled_start_at?->lt($endsAt) && $appointment->scheduled_end_at?->gt($startsAt))) {
                $status = 'temporarily_held';
            }
            $slots[] = ['start_at' => $time, 'status' => $status];
        }

        return ['day_status' => 'open', 'duration_minutes' => $durationMinutes, 'slots' => $slots];
    }

    /** @param array<string, mixed> $settings @return array{opens_at:Carbon,closes_at:Carbon}|null */
    private function scheduleWindow(Carbon $date, array $settings): ?array
    {
        $closure = WorkshopClosure::query()->whereDate('closure_date', $date->toDateString())->first();
        if ($closure?->is_closed || (! $closure && ! in_array($date->isoWeekday(), array_map('intval', $settings['workdays'] ?? []), true))) {
            return null;
        }

        $opensAt = $date->copy()->setTimeFromTimeString($closure?->opens_at ? substr($closure->opens_at, 0, 5) : ($settings['open'] ?? '09:00'));
        $closesAt = $date->copy()->setTimeFromTimeString($closure?->closes_at ? substr($closure->closes_at, 0, 5) : ($settings['close'] ?? '21:00'));
        if ($closesAt->lte($opensAt)) {
            return null;
        }

        return ['opens_at' => $opensAt, 'closes_at' => $closesAt];
    }

    /** @param list<int> $requiredCategoryIds @return array{date:string,start_at:string,end_at:string,duration_minutes:int}|null */
    public function recommendEarliest(Carbon $from, int $durationMinutes, array $requiredCategoryIds = [], int $days = 60): ?array
    {
        for ($offset = 0; $offset <= $days; $offset++) {
            $date = $from->copy()->addDays($offset);
            $slots = $this->availableSlots($date, $durationMinutes, $requiredCategoryIds);
            if ($slots !== []) {
                $start = Carbon::createFromFormat('Y-m-d\\TH:i', $slots[0], $date->getTimezone());

                return ['date' => $date->toDateString(), 'start_at' => $slots[0], 'end_at' => $start->copy()->addMinutes($durationMinutes)->format('Y-m-d\\TH:i'), 'duration_minutes' => $durationMinutes];
            }
        }

        return null;
    }

    /** @param Collection<int, array{id:int, capacity:int, used:int, specializations:list<int>, appointments:Collection}> $profiles */
    private function hasQualifiedTeam(Collection $profiles, array $requiredCategoryIds, int $durationMinutes): bool
    {
        $profileCount = $profiles->count();
        for ($teamSize = 1; $teamSize <= min(8, $profileCount); $teamSize++) {
            $minutesEach = (int) ceil($durationMinutes / $teamSize);
            $eligible = $profiles->filter(fn (array $profile): bool => $minutesEach <= $profile['capacity'] - $profile['used'])->values()->all();
            if (count($eligible) < $teamSize) {
                continue;
            }

            $search = function (int $offset, array $selected, array $covered) use (&$search, $eligible, $teamSize, $requiredCategoryIds): bool {
                if (count($selected) === $teamSize) {
                    return array_diff($requiredCategoryIds, $covered) === [];
                }
                if (count($eligible) - $offset < $teamSize - count($selected)) {
                    return false;
                }

                for ($index = $offset; $index < count($eligible); $index++) {
                    $nextCovered = array_values(array_unique([...$covered, ...$eligible[$index]['specializations']]));
                    if ($search($index + 1, [...$selected, $eligible[$index]['id']], $nextCovered)) {
                        return true;
                    }
                }

                return false;
            };

            if ($search(0, [], [])) {
                return true;
            }
        }

        return false;
    }
}
