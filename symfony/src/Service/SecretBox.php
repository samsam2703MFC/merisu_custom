<?php

declare(strict_types=1);

namespace Merisu\Inventory\Service;

/**
 * Chiffre les secrets qu'il faut pouvoir RELIRE.
 *
 * Distinct de `PinHasher`, et pour une raison de fond : un code PIN se
 * VÉRIFIE, on n'a jamais besoin de le retrouver, donc une empreinte suffit et
 * c'est mieux. Un secret de caisse, lui, doit être présenté tel quel à GoPOS à
 * chaque appel : il faut donc un chiffrement réversible, ce qui est
 * strictement moins sûr, et ne se justifie que là.
 *
 * ── La clé n'est PAS dans la base
 *
 * Elle dérive d'`APP_SECRET`, qui vit dans `.env.local`. Une base dérobée
 * seule ne livre donc rien : il faut aussi le fichier de configuration du
 * serveur. C'est exactement la protection que `PinHasher` applique déjà aux
 * codes, et il n'y avait pas de raison d'en inventer une seconde.
 *
 * ⚠️ Changer `APP_SECRET` rend les secrets chiffrés illisibles. C'est voulu —
 * une rotation de secret d'application DOIT invalider ce qu'il protégeait — et
 * l'appelant le voit : le déchiffrement rend null, et l'écran redemande la
 * saisie plutôt que d'envoyer des octets au hasard à la caisse.
 */
final readonly class SecretBox
{
    /** Marque le format, pour qu'un changement d'algorithme se reconnaisse. */
    private const PREFIX = 'sb1:';

    public function __construct(
        #[\SensitiveParameter]
        private string $appSecret,
    ) {
    }

    /**
     * Le chiffrement est-il possible sur cette machine ?
     *
     * `ext-sodium` est compilée par défaut depuis PHP 7.2, mais certains
     * hébergeurs mutualisés la retirent. Mieux vaut le dire à l'écran que
     * refuser un enregistrement sans expliquer pourquoi.
     */
    public function isAvailable(): bool
    {
        return \extension_loaded('sodium') && trim($this->appSecret) !== '';
    }

    /** Rend null si le chiffrement n'est pas possible — l'appelant refuse alors d'écrire. */
    public function encrypt(#[\SensitiveParameter] string $plain): ?string
    {
        if (!$this->isAvailable() || $plain === '') {
            return null;
        }

        // Un nonce neuf à chaque chiffrement : deux fois le même secret ne
        // produit pas deux fois la même chaîne, et l'on ne peut donc pas
        // deviner qu'une valeur n'a pas changé en comparant deux sauvegardes.
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($plain, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $chiffre);
    }

    /** Rend null si la valeur est illisible — clé changée, base modifiée à la main. */
    public function decrypt(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX) || !$this->isAvailable()) {
            return null;
        }

        $brut = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        if ($brut === false || strlen($brut) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $clair = sodium_crypto_secretbox_open(
            substr($brut, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($brut, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );

        return $clair === false ? null : $clair;
    }

    /**
     * La clé, dérivée d'`APP_SECRET`.
     *
     * Un hachage plutôt que la chaîne brute : `APP_SECRET` n'a ni la longueur
     * ni la forme qu'attend sodium, et le tronquer aurait affaibli une clé
     * pourtant longue.
     */
    private function key(): string
    {
        return hash('sha256', 'merisu.secretbox.v1|' . $this->appSecret, true);
    }
}
