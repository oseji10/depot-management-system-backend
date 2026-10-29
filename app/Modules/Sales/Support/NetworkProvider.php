<?php

namespace App\Modules\Sales\Support;

/**
 * Which mobile network a Nigerian phone number started on, read from its prefix. Number portability means a customer
 * may have moved since, so treat it as a good guide rather than a fact.
 */
class NetworkProvider
{
    public const NETWORKS = ['MTN', 'Airtel', 'Glo', '9mobile', 'Other', 'No phone'];

    private const PREFIXES = [
        'MTN' => ['0702', '0703', '0704', '0706', '0803', '0806', '0810', '0813', '0814', '0816', '0903', '0906', '0913', '0916', '07025', '07026'],
        'Airtel' => ['0701', '0708', '0802', '0808', '0812', '0901', '0902', '0904', '0907', '0912'],
        'Glo' => ['0705', '0805', '0807', '0811', '0815', '0905', '0915'],
        '9mobile' => ['0809', '0817', '0818', '0908', '0909'],
    ];

    /** Local 11-digit form (0803...), or null when there is no usable number. */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '234') && strlen($digits) >= 13) {
            $digits = '0'.substr($digits, 3);
        } elseif (strlen($digits) === 10) {
            $digits = '0'.$digits;
        }

        return strlen($digits) === 11 && $digits[0] === '0' ? $digits : $digits;
    }

    public static function detect(?string $phone): string
    {
        $local = self::normalise($phone);

        if ($local === null) {
            return 'No phone';
        }

        foreach (self::PREFIXES as $network => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($local, $prefix)) {
                    return $network;
                }
            }
        }

        return 'Other';
    }
}
