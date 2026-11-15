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

    public function copy(): self
    {
        $copy = new self();
        $copy->properties = $this->properties;

        return $copy;
    }

    /**
     * A copy holding only the properties that travel with an application message, or
     * null when none are left.
     */
    public function forwardable(): ?self
    {
        $copy = new self();
        foreach ($this->properties as $id => $value) {
            if (PropertyId::from($id)->isForwardedWithMessage()) {
                $copy->properties[$id] = $value;
            }
        }

        return $copy->isEmpty() ? null : $copy;
    }
}
