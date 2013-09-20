<?php
/**
 * Created by Mikhail Kurachkin.
 * Date: 09.10.12
 * Time: 10:13
 * Info: added convert string to date and back to DateTime class
 */
class CiDateTime extends DateTime
{
    function __construct()
    {
        date_default_timezone_set('Europe/Minsk');
        parent::__construct();
    }

    function to_string()
    {
        return $this->format('Ymd_his');
    }

    function from_string($string_date)
    {
        preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})_([0-9]{2})([0-9]{2})([0-9]{2}).*/s', $string_date, $matches);
        if (!$matches)
            return false;

        $this->setDate($matches[1], $matches[2], $matches[3]);
        $this->setTime($matches[4], $matches[5], $matches[6]);
    }
}
