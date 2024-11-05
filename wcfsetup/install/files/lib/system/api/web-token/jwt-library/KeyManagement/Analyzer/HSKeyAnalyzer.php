<?php

declare(strict_types=1);

namespace Jose\Component\KeyManagement\Analyzer;

use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Base64UrlSafe;
use function is_string;

abstract class HSKeyAnalyzer implements KeyAnalyzer
{
    public function analyze(JWK $jwk, MessageBag $bag): void
    {
        if ($jwk->get('kty') !== 'oct') {
            return;
        }
        if (! $jwk->has('alg') || $jwk->get('alg') !== $this->getAlgorithmName()) {
            return;
        }
        $k = $jwk->get('k');
        if (! is_string($k)) {
            $bag->add(Message::high('The key is not valid'));

            return;
        }
        $k = Base64UrlSafe::decodeNoPadding($k);
        $kLength = 8 * mb_strlen($k, '8bit');
        if ($kLength < $this->getMinimumKeySize()) {
            $bag->add(
                Message::high(sprintf(
                    'HS512 algorithm requires at least %d bits key length.',
                    $this->getMinimumKeySize()
                ))
            );
        }
    }

    abstract protected function getAlgorithmName(): string;

    abstract protected function getMinimumKeySize(): int;
}
