<?php

namespace App\Service;

use AltchaOrg\Altcha\V1\Altcha;
use AltchaOrg\Altcha\V1\ChallengeOptions;
use AltchaOrg\Altcha\V1\Hasher\Algorithm;

/**
 * ALTCHA self-hosted: genera y verifica los desafíos en el propio servidor.
 * No depende de la API de altcha.org (el plan gratuito fue discontinuado).
 */
class AltchaService
{
    private const MAX_NUMBER = 100000;
    private const CHALLENGE_TTL = '+10 minutes';

    private Altcha $altcha;

    public function __construct()
    {
        $this->altcha = new Altcha((string) ($_ENV['ALTCHA_HMAC_KEY'] ?? ''));
    }

    public function isConfigured(): bool
    {
        return (string) ($_ENV['ALTCHA_HMAC_KEY'] ?? '') !== '';
    }

    /**
     * @return array{algorithm: string, challenge: string, maxnumber: int, salt: string, signature: string}
     */
    public function createChallenge(): array
    {
        $challenge = $this->altcha->createChallenge(new ChallengeOptions(
            algorithm: Algorithm::SHA256,
            maxNumber: self::MAX_NUMBER,
            expires: new \DateTimeImmutable(self::CHALLENGE_TTL),
        ));

        // El widget espera estas claves exactas (maxnumber en minúscula).
        return [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'maxnumber' => $challenge->maxNumber,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
        ];
    }

    public function verify(string $payload): bool
    {
        if (!$this->isConfigured()) {
            // Sin clave configurada no se puede verificar. En dev no bloqueamos
            // el portal; en prod el captcha falla cerrado.
            return ($_ENV['APP_ENV'] ?? 'prod') !== 'prod';
        }

        if ($payload === '') {
            return false;
        }

        return $this->altcha->verifySolution($payload);
    }
}
