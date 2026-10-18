<?php

namespace App\Support\Database;

/**
 * Eloquent formats dates as "Y-m-d H:i:s" without converting time zones, so a
 * Carbon instance in Asia/Riyadh (or an input such as "09:00+03:00") would be
 * persisted three hours off. Every model storing instants must use this
 * trait: values are converted to UTC before they are written.
 */
trait StoresUtcDates
{
    /**
     * @param  mixed  $value
     * @return string|null
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }
}
