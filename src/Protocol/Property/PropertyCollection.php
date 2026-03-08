<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Property;

final class PropertyCollection
{
    /** @var array<int, mixed> propertyId => value (multi-value properties stored as arrays) */
    private array $properties = [];

    public function set(PropertyId $id, mixed $value): void
    {
        if ($id->isMultiValue()) {
            $this->properties[$id->value][] = $value;
        } else {
            $this->properties[$id->value] = $value;
        }
    }

    public function get(PropertyId $id): mixed
    {
        return $this->properties[$id->value] ?? null;
    }

    public function has(PropertyId $id): bool
    {
        return isset($this->properties[$id->value]);
    }

    public function remove(PropertyId $id): void
    {
        unset($this->properties[$id->value]);
    }

    /**
     * @return array<int, mixed>
     */
    public function getAll(): array
    {
        return $this->properties;
    }

    public function isEmpty(): bool
    {
        return $this->properties === [];
    }
}
