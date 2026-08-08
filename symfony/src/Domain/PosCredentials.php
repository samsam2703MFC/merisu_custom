<?php

declare(strict_types=1);

namespace Merisu\Inventory\Domain;

/**
 * Les trois valeurs qui ouvrent la caisse — et D'OÙ elles viennent.
 *
 * La provenance n'est pas un détail d'affichage. Un administrateur qui a saisi
 * un identifiant à l'écran et voit la caisse répondre autre chose doit pouvoir
 * comprendre qu'une variable d'environnement du serveur ne prend pas le pas
 * sur lui — ou l'inverse. Sans cette information, le réglage devient
 * indébrouillable dès qu'il existe à deux endroits.
 */
final readonly class PosCredentials
{
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public string $organizationId,
        public string $baseUrl,
        /** Vrai quand ces valeurs viennent de l'écran, faux quand du serveur. */
        public bool $fromScreen = false,
    ) {
    }

    public function isComplete(): bool
    {
        return trim($this->clientId) !== ''
            && trim($this->clientSecret) !== ''
            && trim($this->organizationId) !== '';
    }

    /**
     * Ce que l'écran a le droit de montrer.
     *
     * Le secret n'en fait PAS partie, jamais, même masqué au milieu : « sk-…
     * 4f2a » suffit à confirmer à qui l'a volé qu'il tient le bon. On dit
     * seulement qu'il est posé.
     *
     * @return array{clientId: string, organizationId: string, baseUrl: string, hasSecret: bool}
     */
    public function display(): array
    {
        return [
            'clientId' => $this->clientId,
            'organizationId' => $this->organizationId,
            'baseUrl' => $this->baseUrl,
            'hasSecret' => trim($this->clientSecret) !== '',
        ];
    }
}
