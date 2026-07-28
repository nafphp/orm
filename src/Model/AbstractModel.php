<?php

declare(strict_types=1);

namespace NixPHP\ORM\Model;

use NixPHP\ORM\Core\EntityInterface;
use NixPHP\ORM\Core\EntityTrait;

abstract class AbstractModel implements EntityInterface
{

    use EntityTrait;

    protected ?int $id;

    /**
     * Constructs a new instance of the class and initializes its properties based on the provided data.
     *
     * @param array|null $data An optional array of key-value pairs used to initialize the properties of the class.
     *
     * @return void
     */
    public function __construct(?array $data = [])
    {
        $this->id = $data['id'] ?? null;
        foreach ($data as $key => $value) {
            $ref = new \ReflectionObject($this);
            if ($ref->hasProperty($key)) {
                $this->$key = $value;
            }
        }
    }

}
