<?php

namespace Mlangeni\Machinjiri\Core\Date;

use \DateTimeZone;
use \DateTime;
use \DateInterval;
use Mlangeni\Machinjiri\Core\Exceptions\DateTimeException;

class DateTimeHandler {

    private DateTime $dateTime;

    public function __construct(string $dateString = 'now', string $timezone = 'UTC') {
        try {
            $timezoneObj = new DateTimeZone($timezone);
            $this->dateTime = new DateTime($dateString, $timezoneObj);
        } catch (\Throwable $e) {
            throw new DateTimeException("Invalid date/time or timezone: " . $e->getMessage());
        }
    }

    public function format(string $format): string {
        return $this->dateTime->format($format);
    }

    public function modify(string $modifier): self {
        $this->dateTime->modify($modifier);
        return $this;
    }

    public function addInterval(DateInterval $interval): self {
        $this->dateTime->add($interval);
        return $this;
    }

    public function subInterval(DateInterval $interval): self {
        $this->dateTime->sub($interval);
        return $this;
    }

    public function diff(DateTimeHandler $other): DateInterval {
        return $this->dateTime->diff($other->getDateTime());
    }

    public function setTime(int $hour, int $minute, int $second = 0): self {
        $this->dateTime->setTime($hour, $minute, $second);
        return $this;
    }

    public function setDate(int $year, int $month, int $day): self {
        $this->dateTime->setDate($year, $month, $day);
        return $this;
    }

    public function getDateTime(): DateTime {
        return $this->dateTime;
    }

    public function getTimestamp(): int {
        return $this->dateTime->getTimestamp();
    }

    // Helper methods for common operations
    public function addDays(int $days): self {
        return $this->addInterval(new DateInterval("P{$days}D"));
    }

    public function addHours(int $hours): self {
        return $this->addInterval(new DateInterval("PT{$hours}H"));
    }

    public function addMinutes(int $minutes): self {
        return $this->addInterval(new DateInterval("PT{$minutes}M"));
    }

    public function subDays(int $days): self {
        return $this->subInterval(new DateInterval("P{$days}D"));
    }

    public function subHours(int $hours): self {
        return $this->subInterval(new DateInterval("PT{$hours}H"));
    }

    public function subMinutes(int $minutes): self {
        return $this->subInterval(new DateInterval("PT{$minutes}M"));
    }

    // Static method for creating from timestamp
    public static function fromTimestamp(int $timestamp, string $timezone = 'UTC'): self {
        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        $dateTime->setTimezone(new DateTimeZone($timezone));
        return new self($dateTime->format('Y-m-d H:i:s'), $timezone);
    }
}