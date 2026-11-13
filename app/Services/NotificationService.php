<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\User;

class NotificationService
{
    /** @param array<string, mixed> $data */
    public function send(User $user, string $type, string $title, string $body, ?string $url = null, array $data = []): InternalNotification
    {
        return InternalNotification::create(compact('type', 'title', 'body', 'url', 'data') + ['user_id' => $user->id]);
    }

    /** @param array<string, mixed> $data */
    public function sendToRole(string $role, string $type, string $title, string $body, ?string $url = null, array $data = []): void
    {
        $this->sendToRoles([$role], $type, $title, $body, $url, $data);
    }

    /** @param list<string> $roles
     * @param  array<string, mixed>  $data
     */
    public function sendToRoles(array $roles, string $type, string $title, string $body, ?string $url = null, array $data = []): void
    {
        User::query()->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))->get()->each(fn (User $user) => $this->send($user, $type, $title, $body, $url, $data));
    }
}
