<?php

namespace App\Security\WebAuthn;

/**
 * A small CBOR (RFC 8949) reader, enough for WebAuthn attestation objects and COSE keys.
 * Byte strings come back as PHP strings, maps as arrays keyed by their int or text keys.
 */
class Cbor
{
    private const MAX_DEPTH = 16;

    private int $offset;

    private function __construct(private readonly string $data, int $offset)
    {
        $this->offset = $offset;
    }

    /**
     * Decode one item that fills the whole string.
     */
    public static function decode(string $data): mixed
    {
        [$value, $end] = self::decodeAt($data, 0);

        if ($end !== strlen($data)) {
            throw new WebAuthnException('Unexpected data after the CBOR item.');
        }

        return $value;
    }

    /**
     * Decode one item starting at $offset. Returns the value and the offset just after it.
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeAt(string $data, int $offset): array
    {
        $reader = new self($data, $offset);
        $value = $reader->item(0);

        return [$value, $reader->offset];
    }

    private function item(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new WebAuthnException('The CBOR data is nested too deeply.');
        }

        $initial = ord($this->read(1));
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return $this->simple($info);
        }

        $length = $this->length($info);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => $this->read($length),
            3 => $this->read($length),
            4 => $this->array($length, $depth),
            5 => $this->map($length, $depth),
            6 => $this->item($depth + 1),
        };
    }

    private function length(int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        $value = match ($info) {
            24 => ord($this->read(1)),
            25 => unpack('n', $this->read(2))[1],
            26 => unpack('N', $this->read(4))[1],
            27 => unpack('J', $this->read(8))[1],
            default => throw new WebAuthnException('Unsupported CBOR length.'),
        };

        if ($value < 0) {
            throw new WebAuthnException('The CBOR length is too large.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function array(int $length, int $depth): array
    {
        $items = [];

        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->item($depth + 1);
        }

        return $items;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function map(int $length, int $depth): array
    {
        $map = [];

        for ($i = 0; $i < $length; $i++) {
            $key = $this->item($depth + 1);

            if (! is_int($key) && ! is_string($key)) {
                throw new WebAuthnException('Unsupported CBOR map key.');
            }

            $map[$key] = $this->item($depth + 1);
        }

        return $map;
    }

    private function simple(int $info): mixed
    {
        return match ($info) {
            20 => false,
            21 => true,
            22, 23 => null,
            25 => $this->halfFloat(),
            26 => unpack('G', $this->read(4))[1],
            27 => unpack('E', $this->read(8))[1],
            default => throw new WebAuthnException('Unsupported CBOR value.'),
        };
    }

    private function halfFloat(): float
    {
        $half = unpack('n', $this->read(2))[1];
        $exponent = ($half >> 10) & 0x1F;
        $mantissa = $half & 0x3FF;
        $value = match ($exponent) {
            0 => $mantissa * 2 ** -24,
            31 => $mantissa === 0 ? INF : NAN,
            default => ($mantissa + 1024) * 2 ** ($exponent - 25),
        };

        return $half & 0x8000 ? -$value : $value;
    }

    private function read(int $length): string
    {
        if ($length > strlen($this->data) - $this->offset) {
            throw new WebAuthnException('The CBOR data ended too early.');
        }

        $bytes = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }
}
