<?php

namespace Mlangeni\Machinjiri\Core\Date;

use Mlangeni\Machinjiri\Core\Exceptions\DateTimeException;
use DateTime;
use DateInterval;
use DateMalformedStringException;
use DateTimeZone;

class DateTimeHandler
{
    private DateTime $dateTime;

    public function __construct(string $dateString = 'now', string $timezone = 'UTC')
    {
        try {
            $timezoneObj = new DateTimeZone($timezone);
            $dateTime = new DateTime($dateString, $timezoneObj);
            $errors = DateTime::getLastErrors();

            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new DateMalformedStringException(
                    'Could not parse date/time: ' . $dateString
                );
            }

            $this->dateTime = $dateTime;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Invalid date/time or timezone: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function format(string $format): string
    {
        return $this->dateTime->format($format);
    }

    public function modify(string $modifier): self
    {
        try {
            $modified = $this->dateTime->modify(trim($modifier));

            if ($modified === false) {
                throw new \InvalidArgumentException('Invalid date modifier: ' . $modifier);
            }

            return $this;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Unable to modify date/time: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function addInterval(DateInterval $interval): self
    {
        try {
            $this->dateTime->add($interval);
            return $this;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Unable to add date interval: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function subInterval(DateInterval $interval): self
    {
        try {
            $this->dateTime->sub($interval);
            return $this;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Unable to subtract date interval: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function diff(DateTimeHandler $other): DateInterval
    {
        return $this->dateTime->diff($other->getDateTime());
    }

    public function setTime(int $hour, int $minute, int $second = 0): self
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new DateTimeException('Time components must be within 0-23:0-59:0-59');
        }

        try {
            $this->dateTime->setTime($hour, $minute, $second);
            return $this;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Unable to set time: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function setDate(int $year, int $month, int $day): self
    {
        if (!checkdate($month, $day, $year)) {
            throw new DateTimeException(
                sprintf('Invalid calendar date: %04d-%02d-%02d', $year, $month, $day)
            );
        }

        try {
            $this->dateTime->setDate($year, $month, $day);
            return $this;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Unable to set date: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function getDateTime(): DateTime
    {
        return clone $this->dateTime;
    }

    public function getTimestamp(): int
    {
        return $this->dateTime->getTimestamp();
    }

    // Helper methods for common operations
    public function addDays(int $days): self
    {
        if ($days < 0) {
            return $this->subDays(-$days);
        }

        return $days === 0
            ? $this
            : $this->addInterval(new DateInterval("P{$days}D"));
    }

    public function addHours(int $hours): self
    {
        if ($hours < 0) {
            return $this->subHours(-$hours);
        }

        return $hours === 0
            ? $this
            : $this->addInterval(new DateInterval("PT{$hours}H"));
    }

    public function addMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            return $this->subMinutes(-$minutes);
        }

        return $minutes === 0
            ? $this
            : $this->addInterval(new DateInterval("PT{$minutes}M"));
    }

    public function subDays(int $days): self
    {
        if ($days < 0) {
            return $this->addDays(-$days);
        }

        return $days === 0
            ? $this
            : $this->subInterval(new DateInterval("P{$days}D"));
    }

    public function subHours(int $hours): self
    {
        if ($hours < 0) {
            return $this->addHours(-$hours);
        }

        return $hours === 0
            ? $this
            : $this->subInterval(new DateInterval("PT{$hours}H"));
    }

    public function subMinutes(int $minutes): self
    {
        if ($minutes < 0) {
            return $this->addMinutes(-$minutes);
        }

        return $minutes === 0
            ? $this
            : $this->subInterval(new DateInterval("PT{$minutes}M"));
    }

    // Static method for creating from timestamp
    public static function fromTimestamp(int $timestamp, string $timezone = 'UTC'): self
    {
        try {
            $timezoneObj = new DateTimeZone($timezone);
            $dateTime = (new DateTime('@' . $timestamp))->setTimezone($timezoneObj);
            $handler = new self('now', $timezone);
            $handler->dateTime = $dateTime;

            return $handler;
        } catch (\Throwable $e) {
            throw new DateTimeException(
                'Invalid timestamp or timezone: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}