<?php

namespace Kue;

class Util
{
    /**
     * Get current mill-seconds
     *
     * @return int
     */
    public static function now()
    {
        return microtime(true) * 1000 | 0;
    }
}