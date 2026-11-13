<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Service;
use Illuminate\Support\Collection;

class LaborPricingService
{
    /** @return array<string, array{amount_rupiah:int,interval_minutes:int,minimum_minutes:int,active:bool,provisional:bool}> */
    public function rates(): array
    {
        return array_replace_recursive([
            'cvt' => ['amount_rupiah' => 15000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'frame' => ['amount_rupiah' => 10000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'engine' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => true, 'provisional' => false],
            'electrical_diagnosis' => ['amount_rupiah' => 25000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => false, 'provisional' => true],
            'electrical_installation' => ['amount_rupiah' => 20000, 'interval_minutes' => 30, 'minimum_minutes' => 30, 'active' => false, 'provisional' => true],
        ], BusinessSetting::value('labor_rates', []));
    }

    public function price(Service $service): ?int
    {
        if ($service->labor_pricing_mode !== 'time_based') {
            return $service->labor_price_rupiah;
        }
        if (! $service->labor_category || ! $service->duration_minutes) {
            return null;
        }

        $rate = $this->rates()[$service->labor_category] ?? null;

        return $rate && $rate['active'] && ! $rate['provisional'] ? $this->timePrice((int) $service->duration_minutes, $rate) : null;
    }

    /** @param Collection<int, Service> $services @return array<int, int|null> */
    public function forServices(Collection $services, array $extraMinutesByService = []): array
    {
        $prices = [];
        $groups = [];
        $rates = $this->rates();
        foreach ($services as $service) {
            if ($service->labor_pricing_mode !== 'time_based') {
                $prices[$service->id] = $service->labor_price_rupiah;

                continue;
            }
            if (! $service->labor_category || ! $service->duration_minutes || ! isset($rates[$service->labor_category]) || ! $rates[$service->labor_category]['active'] || $rates[$service->labor_category]['provisional']) {
                $prices[$service->id] = null;

                continue;
            }
            $group = $this->groupKey($service);
            $laborMinutes = (int) $service->duration_minutes + (int) ($extraMinutesByService[$service->id] ?? 0);
            $sharedMinutes = filled($service->labor_group) ? (int) ($service->shared_operation_minutes ?? 0) : 0;
            $exclusiveMinutes = max(0, $laborMinutes - $sharedMinutes);
            $groups[$group]['services'][] = ['service' => $service, 'minutes' => $exclusiveMinutes];
            $groups[$group]['rate'] = $rates[$service->labor_category];
            $groups[$group]['exclusive_minutes'] = ($groups[$group]['exclusive_minutes'] ?? 0) + $exclusiveMinutes;
            $groups[$group]['shared_minutes'] = max($groups[$group]['shared_minutes'] ?? 0, $sharedMinutes);
        }

        foreach ($groups as $group) {
            $totalMinutes = $group['exclusive_minutes'] + $group['shared_minutes'];
            $groupTotal = $this->timePrice($totalMinutes, $group['rate']);
            $group['services'][0]['minutes'] += $group['shared_minutes'];
            $allocated = 0;
            $lastIndex = count($group['services']) - 1;
            foreach ($group['services'] as $index => $serviceEntry) {
                $share = $index === $lastIndex
                    ? $groupTotal - $allocated
                    : (int) floor($groupTotal * $serviceEntry['minutes'] / $totalMinutes);
                $prices[$serviceEntry['service']->id] = $share;
                $allocated += $share;
            }
        }

        return $prices;
    }

    /** @param Collection<int, Service> $services */
    public function durationForServices(Collection $services, array $extraMinutesByService = []): int
    {
        $duration = 0;
        $groups = [];

        foreach ($services as $service) {
            $minutes = (int) ($service->duration_minutes ?? 0) + (int) ($extraMinutesByService[$service->id] ?? 0);
            if (! filled($service->labor_group)) {
                $duration += $minutes;

                continue;
            }

            $key = $this->groupKey($service);
            $sharedMinutes = (int) ($service->shared_operation_minutes ?? 0);
            $groups[$key]['exclusive_minutes'] = ($groups[$key]['exclusive_minutes'] ?? 0) + max(0, $minutes - $sharedMinutes);
            $groups[$key]['shared_minutes'] = max($groups[$key]['shared_minutes'] ?? 0, $sharedMinutes);
        }

        foreach ($groups as $group) {
            $duration += $group['exclusive_minutes'] + $group['shared_minutes'];
        }

        return $duration;
    }

    private function groupKey(Service $service): string
    {
        return ($service->labor_group ?: 'service-'.$service->id).'|'.($service->labor_category ?: 'uncategorized');
    }

    /** @param array{amount_rupiah:int,interval_minutes:int,minimum_minutes:int,active:bool,provisional:bool} $rate */
    private function timePrice(int $durationMinutes, array $rate): int
    {
        $interval = max(1, (int) $rate['interval_minutes']);
        $minimum = max(1, (int) $rate['minimum_minutes']);
        $blocks = max((int) ceil($minimum / $interval), (int) ceil($durationMinutes / $interval));

        return $blocks * (int) $rate['amount_rupiah'];
    }
}
