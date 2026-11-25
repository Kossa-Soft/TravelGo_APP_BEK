<?php

namespace App\Support;

enum TravelGoType: string
{
    case Country = 'countries';
    case Region = 'regions';
    case City = 'cities';
    case Place = 'places';
    case Route = 'routes';

    public function label(): string
    {
        return match ($this) {
            self::Country => 'Страны',
            self::Region => 'Регионы',
            self::City => 'Города',
            self::Place => 'Места',
            self::Route => 'Маршруты',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Country => 'Страна',
            self::Region => 'Регион',
            self::City => 'Город',
            self::Place => 'Место',
            self::Route => 'Маршрут',
        };
    }

    public function parentType(): ?self
    {
        return match ($this) {
            self::Country => null,
            self::Region, self::City => self::Country,
            self::Place, self::Route => self::City,
        };
    }

    public function permission(string $action): string
    {
        return 'travelgo.'.$this->value.'.'.$action;
    }
}
