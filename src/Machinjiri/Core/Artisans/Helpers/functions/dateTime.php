<?php

use Mlangeni\Machinjiri\Core\Date\DateTimeHandler;

if (!function_exists('datetime_handler')) {
    /**
     * Get current DateTimeHandler instance
     *
     * @param string|null $timezone Timezone string (default: UTC or from config)
     * @return DateTimeHandler
     */
    function datetime_handler(?string $timezone = null): DateTimeHandler
    {
        // Get timezone from parameter, config, or default to UTC
        $tz = $timezone ?? config('app.timezone', 'UTC');
        
        return new DateTimeHandler('now', $tz);
    }
}

if (!function_exists('now')) {
    /**
     * Get current date and time
     *
     * @param string $format
     * @return string
     */
    function now(string $format = ''): string
    {
        if (empty($format)) {
            $format = 'Y-m-d h:i:s';
        }
        return datetime_handler()->format($format);
    }
}