<?php

namespace App\Support;

use SensitiveParameter;

class AdminTotp
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        $secret = '';

        for ($index = 0; $index < 32; $index++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public function matchingStep(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code, int $timestamp): ?int
    {
        if (strlen($secret) !== 32 || strspn($secret, self::ALPHABET) !== 32) {
            return null;
        }

        $key = $this->decodeSecret($secret);
        $currentStep = intdiv($timestamp, 30);

        foreach ([$currentStep, $currentStep - 1] as $step) {
            $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
            $offset = ord($hmac[19]) & 0x0F;
            $value = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;
            $expected = str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);

            if (hash_equals($expected, $code)) {
                return $step;
            }
        }

        return null;
    }

    private function decodeSecret(#[SensitiveParameter] string $secret): string
    {
        $key = '';
        $buffer = 0;
        $bits = 0;

        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos(self::ALPHABET, $character);
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr($buffer >> $bits);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return $key;
    }
}
