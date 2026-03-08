<?php

declare(strict_types=1);

namespace PhpMqtt\Broker\Protocol\Property;

enum PropertyId: int
{
    case PayloadFormatIndicator = 0x01;
    case MessageExpiryInterval = 0x02;
    case ContentType = 0x03;
    case ResponseTopic = 0x08;
    case CorrelationData = 0x09;
    case SubscriptionIdentifier = 0x0B;
    case SessionExpiryInterval = 0x11;
    case AssignedClientIdentifier = 0x12;
    case ServerKeepAlive = 0x13;
    case AuthenticationMethod = 0x15;
    case AuthenticationData = 0x16;
    case RequestProblemInformation = 0x17;
    case WillDelayInterval = 0x18;
    case RequestResponseInformation = 0x19;
    case ResponseInformation = 0x1A;
    case ServerReference = 0x1C;
    case ReasonString = 0x1F;
    case ReceiveMaximum = 0x21;
    case TopicAliasMaximum = 0x22;
    case TopicAlias = 0x23;
    case MaximumQoS = 0x24;
    case RetainAvailable = 0x25;
    case UserProperty = 0x26;
    case MaximumPacketSize = 0x27;
    case WildcardSubscriptionAvailable = 0x28;
    case SubscriptionIdentifierAvailable = 0x29;
    case SharedSubscriptionAvailable = 0x2A;

    /**
     * Returns the data type for encoding/decoding.
     * 'byte', 'two_byte', 'four_byte', 'variable_int', 'utf8', 'binary', 'utf8_pair'
     */
    public function dataType(): string
    {
        return match ($this) {
            self::PayloadFormatIndicator,
            self::RequestProblemInformation,
            self::RequestResponseInformation,
            self::MaximumQoS,
            self::RetainAvailable,
            self::WildcardSubscriptionAvailable,
            self::SubscriptionIdentifierAvailable,
            self::SharedSubscriptionAvailable => 'byte',

            self::ServerKeepAlive,
            self::ReceiveMaximum,
            self::TopicAliasMaximum,
            self::TopicAlias => 'two_byte',

            self::MessageExpiryInterval,
            self::SessionExpiryInterval,
            self::WillDelayInterval,
            self::MaximumPacketSize => 'four_byte',

            self::SubscriptionIdentifier => 'variable_int',

            self::ContentType,
            self::ResponseTopic,
            self::AssignedClientIdentifier,
            self::AuthenticationMethod,
            self::ResponseInformation,
            self::ServerReference,
            self::ReasonString => 'utf8',

            self::CorrelationData,
            self::AuthenticationData => 'binary',

            self::UserProperty => 'utf8_pair',
        };
    }

    /**
     * Whether this property can appear multiple times in a single property set.
     */
    public function isMultiValue(): bool
    {
        return match ($this) {
            self::UserProperty, self::SubscriptionIdentifier => true,
            default => false,
        };
    }
}
