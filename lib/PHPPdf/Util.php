<?php

namespace PHPPdf;

final class Util
{
    private function __construct()
    {
        throw new \BadMethodCallException(sprintf('Object of "%s" class can not be created.', __CLASS__));
    }
    
    public static function convertFromPercentageValue($percent, $value)
    {
        if(strpos($percent, '%') !== false)
        {
            $percent = (double) $percent;
            $percent = $value*$percent / 100;
        }
        return $percent;
    }    
}