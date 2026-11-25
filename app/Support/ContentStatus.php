<?php

namespace App\Support;

enum ContentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Hidden = 'hidden';
    case Rejected = 'rejected';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Черновик',
            self::InReview => 'На проверке',
            self::Scheduled => 'Запланировано',
            self::Published => 'Опубликовано',
            self::Hidden => 'Скрыто',
            self::Rejected => 'Отклонено',
            self::Archived => 'Архив',
        };
    }

    /** @return array<int, self> */
    public function editorialTransitions(): array
    {
        $next = match ($this) {
            self::Draft => [self::InReview, self::Scheduled, self::Published],
            self::InReview => [self::Draft, self::Scheduled, self::Published, self::Rejected],
            self::Scheduled, self::Rejected, self::Archived => [self::Draft],
            self::Published => [self::Hidden],
            self::Hidden => [self::Published],
        };

        return $this === self::Archived ? $next : [...$next, self::Archived];
    }

    public function action(self $target): string
    {
        return match (true) {
            $target === self::Archived, $this === self::Archived => 'archive',
            in_array($target, [self::Published, self::Scheduled, self::Hidden], true),
            $this === self::Scheduled => 'publish',
            default => 'edit',
        };
    }
}
