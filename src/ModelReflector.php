<?php

namespace Nerio\ModelReflector;

use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Type;

/**
 * @author caojiayuan
 */
class ModelReflector
{

    protected $propertyInfo;

    private $original;

    public function __construct($data)
    {
        $phpDocExtractor = new PhpDocExtractor();
        $reflectionExtractor = new ReflectionExtractor();
        $listExtractors = [$reflectionExtractor];
        $typeExtractors = [$phpDocExtractor, $reflectionExtractor];
        $descriptionExtractors = [$phpDocExtractor];
        $accessExtractors = [$reflectionExtractor];
        $propertyInitializableExtractors = [$reflectionExtractor];
        $this->propertyInfo = new PropertyInfoExtractor(
            $listExtractors,
            $typeExtractors,
            $descriptionExtractors,
            $accessExtractors,
            $propertyInitializableExtractors
        );

        $this->loadFromArray($data);
    }

    public function loadFromArray($data)
    {
        $this->original = $data;

        foreach ($data as $key => $value) {
            $setMethod = 'set' . ucfirst($key);
            if (method_exists($this, $setMethod)) {
                $this->$setMethod($value);
            } else if (property_exists($this, $key)) {
                /** @var Type[] $types */
                $types = $this->propertyInfo->getTypes(static::class, $key);
                if ($types) {
                    foreach ($types as $type) {
                        $builtinType = $type->getBuiltinType();
                        $class = $type->getClassName();
                        $collection = $type->isCollection();
                        if ($builtinType == 'object' && is_subclass_of($class,ModelReflector::class)) {
                            $this->$key = $class::make($value);
                        } else if ($collection) {
                            $ct = $type->getCollectionValueType();
                            $itemClass = $ct->getClassName();
                            if (!is_subclass_of($itemClass,ModelReflector::class)) {
                                throw new \InvalidArgumentException("Model property [{$key}] must an array of instance of ModelReflector");
                            }
                            foreach ($value as $k => $v) {
                                $this->$key[$k] = $itemClass::make($v);
                            }
                        } else {
                            $this->$key = $value;
                        }

                        break;
                    }
                } else {
                    $this->$key = $value;
                }
            }
        }
    }

    public static function make($data)
    {
        return new static($data);
    }

    /**
     * @return mixed
     */
    public function getOriginal()
    {
        return $this->original;
    }
}