<?php

namespace App\Jobs;

use App\Services\AvatarImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class DeleteAvatarFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(private string $user, private string $avatar) {}

    public function handle(AvatarImages $images): void
    {
        if (! $images->delete($this->user, $this->avatar)) {
            throw new RuntimeException('Could not delete the old avatar files.');
        }
    }
}
