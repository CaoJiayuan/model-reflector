<?php

namespace Nerio\ModelReflector;

/**
 * @author caojiayuan
 */
class ModelReflector
{
    /**
     * @param $data
     * @return static
     */
    public static function make($data)
    {
        return Reflector::loadFromArray(static::class, $data);
    }
}