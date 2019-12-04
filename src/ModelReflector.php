<?php

namespace Nerio\ModelReflector;

/**
 * @author caojiayuan
 */
class ModelReflector
{
    public static function make($data)
    {
        return Reflector::loadFromArray(static::class, $data);
    }
}