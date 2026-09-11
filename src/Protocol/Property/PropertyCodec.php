<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Property;

use PhpMqtt\Broker\Exception\MalformedPacketException;
use PhpMqtt\Broker\Protocol\DataType;

final class PropertyCodec
{
    public static function decode(string $data, int &$offset): PropertyCollection
    {
        $collection = new PropertyCollection();

        $propertyLength = DataType::decodeVariableByteInteger($data, $offset);
        $endOffset = $offset + $propertyLength;

        if ($endOffset > strlen($data)) {
            throw new MalformedPacketException('Property length exceeds available data');
        }

        $seen = [];

        while ($offset < $endOffset) {
            $idValue = DataType::decodeVariableByteInteger($data, $offset);
            $id = PropertyId::tryFrom($idValue);

            if ($id === null) {
                throw new MalformedPacketException(sprintf('Unknown property ID 0x%02X', $idValue));
            }

            // A property that may appear only once must not be repeated; otherwise the
            // last write silently wins and the sender chooses which value takes effect.
            if (!$id->isMultiValue()) {
                if (isset($seen[$id->value])) {
                    throw new MalformedPacketException(
                        sprintf('Duplicate property 0x%02X', $id->value),
                    );
                }
                $seen[$id->value] = true;
            }

            $value = self::decodeValue($id, $data, $offset);

            // The declared property length is a boundary, not a hint. A value allowed to
            // read past it consumes the bytes of whatever follows, so the sender controls
            // which properties the broker sees and which it silently skips.
            if ($offset > $endOffset) {
                throw new MalformedPacketException(
                    'Property value overruns the declared property length',
                );
            }

            $collection->set($id, $value);
        }

        if ($offset !== $endOffset) {
            throw new MalformedPacketException('Property length does not match the properties read');
        }

        return $collection;
    }

    public static function encode(PropertyCollection $collection): string
    {
        $propertyData = '';

        foreach ($collection->getAll() as $idValue => $value) {
            $id = PropertyId::from($idValue);

            if ($id->isMultiValue() && is_array($value)) {
                /** @var list<mixed> $value */
                foreach ($value as $item) {
                    $propertyData .= DataType::encodeVariableByteInteger($id->value);
                    $propertyData .= self::encodeValue($id, $item);
                }
            } else {
                $propertyData .= DataType::encodeVariableByteInteger($id->value);
                $propertyData .= self::encodeValue($id, $value);
            }
        }

        return DataType::encodeVariableByteInteger(strlen($propertyData)) . $propertyData;
    }

    /**
     * Encode a property block that may be absent. A packet with no properties still
     * carries a zero length, so the field is never simply omitted.
     */
    public static function encodeOrEmpty(?PropertyCollection $collection): string
    {
        return $collection !== null ? self::encode($collection) : self::encodeEmpty();
    }

    private static function encodeEmpty(): string
    {
        return DataType::encodeVariableByteInteger(0);
    }

    private static function decodeValue(PropertyId $id, string $data, int &$offset): mixed
    {
        return match ($id->dataType()) {
            'byte' => DataType::decodeByte($data, $offset),
            'two_byte' => DataType::decodeTwoByteInteger($data, $offset),
            'four_byte' => DataType::decodeFourByteInteger($data, $offset),
            'variable_int' => DataType::decodeVariableByteInteger($data, $offset),
            'utf8' => DataType::decodeUtf8String($data, $offset),
            'binary' => DataType::decodeBinaryData($data, $offset),
            'utf8_pair' => DataType::decodeUtf8StringPair($data, $offset),
            default => throw new MalformedPacketException('Unknown property data type'),
        };
    }

    private static function encodeValue(PropertyId $id, mixed $value): string
    {
        return match ($id->dataType()) {
            'byte' => DataType::encodeByte((int) $value),
            'two_byte' => DataType::encodeTwoByteInteger((int) $value),
            'four_byte' => DataType::encodeFourByteInteger((int) $value),
            'variable_int' => DataType::encodeVariableByteInteger((int) $value),
            'utf8' => DataType::encodeUtf8String((string) $value),
            'binary' => DataType::encodeBinaryData((string) $value),
            'utf8_pair' => self::encodeStringPair($value),
            default => throw new MalformedPacketException('Unknown property data type'),
        };
    }

    /**
     * @param mixed $value Expected to be array{string, string}
     */
    private static function encodeStringPair(mixed $value): string
    {
        if (!is_array($value) || count($value) !== 2) {
            throw new MalformedPacketException('UTF-8 String Pair must be [key, value]');
        }

        /** @var array{string, string} $value */
        return DataType::encodeUtf8StringPair($value[0], $value[1]);
    }
}
