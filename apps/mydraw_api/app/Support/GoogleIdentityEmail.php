<?php

namespace App\Support;

/**
 * Comparação estável entre o email do perfil e o claim `email` do Google ID Token.
 *
 * Gmail / Googlemail ignoram pontos no utilizador e tratam plus-addressing como o mesmo destino.
 *
 * @see https://support.google.com/mail/answer/7436150
 */
final class GoogleIdentityEmail
{
    /**
     * Email normalizado: trim, NFC unicode e minúsculas.
     */
    public static function normalizeBasic(string $email): string
    {
        $email = trim($email);
        if ($email !== '' && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($email, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $email = $normalized;
            }
        }

        return strtolower($email);
    }

    /**
     * Domínio em ASCII minúsculas (punycode para IDN), para equivalência estável.
     */
    public static function normalizeDomainAscii(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return $domain;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                return strtolower($ascii);
            }
        }

        return $domain;
    }

    /**
     * Forma canónica para decidir se dois endereços são a “mesma conta” Google (Gmail).
     * Outros domínios: normalizeBasic + domínio punycode.
     */
    public static function canonicalForIdentityMatch(string $email): string
    {
        $email = self::normalizeBasic($email);
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $domain = self::normalizeDomainAscii($domain);

        if ($domain === 'googlemail.com') {
            $domain = 'gmail.com';
        }

        if ($domain !== 'gmail.com') {
            return $local.'@'.$domain;
        }

        if (($plus = strpos($local, '+')) !== false) {
            $local = substr($local, 0, $plus);
        }

        $local = str_replace('.', '', $local);

        return $local.'@gmail.com';
    }
}
