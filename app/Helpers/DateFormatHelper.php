<?php

namespace App\Helpers;

use Carbon\Carbon;

class DateFormatHelper
{
    /**
     * Convert date from dd/MM/yyyy format to ISO 8601 (YYYY-MM-DD).
     * 
     * @param string $date Date in dd/MM/yyyy format (e.g., "20/09/1990")
     * @return string Date in YYYY-MM-DD format (e.g., "1990-09-20")
     */
    public static function ddMMyyyyToISO(string $date): string
    {
        return Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');
    }

    /**
     * Convert date from ISO 8601 (YYYY-MM-DD) to dd/MM/yyyy format.
     * 
     * @param string $date Date in YYYY-MM-DD format (e.g., "1990-09-20")
     * @return string Date in dd/MM/yyyy format (e.g., "20/09/1990")
     */
    public static function isoToDdMMyyyy(string $date): string
    {
        return Carbon::createFromFormat('Y-m-d', $date)->format('d/m/Y');
    }
}
