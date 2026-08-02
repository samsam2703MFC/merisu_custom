<?php

declare(strict_types=1);

namespace Merisu\Inventory\Pwa;

/**
 * Empreinte des ressources statiques, servant de version au cache du service
 * worker.
 *
 * POURQUOI : le nom du cache doit changer dès que les ressources changent. Une
 * constante écrite à la main ne suit pas les déploiements — on oublie de
 * l'incrémenter, et les tablettes déjà installées continuent de servir
 * l'ancienne feuille de style indéfiniment. L'empreinte se calcule donc à
 * partir du contenu réel du dossier `assets/`.
 *
 * Le calcul est fait une fois par requête, et seulement pour `/sw.js`, servi
 * une fois par session : le coût est négligeable devant le risque de servir
 * une interface périmée au poste de travail.
 */
final class BuildVersion
{
    private ?string $version = null;

    public function __construct(private readonly string $assetsDir)
    {
    }

    public function get(): string
    {
        return $this->version ??= $this->calculer();
    }

    private function calculer(): string
    {
        if (!is_dir($this->assetsDir)) {
            // Sans dossier lisible, mieux vaut une version qui change à chaque
            // requête qu'une version figée : le cache ne retiendra rien, mais
            // il ne servira jamais de périmé non plus.
            return 'inconnue-' . bin2hex(random_bytes(4));
        }

        $empreintes = [];

        $fichiers = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->assetsDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $fichier */
        foreach ($fichiers as $fichier) {
            if (!$fichier->isFile()) {
                continue;
            }

            // Taille et date suffisent : deux déploiements produisant des
            // fichiers identiques doivent d'ailleurs garder la même version,
            // pour ne pas invalider le cache sans raison.
            $empreintes[] = $fichier->getPathname() . ':' . $fichier->getSize() . ':' . $fichier->getMTime();
        }

        sort($empreintes);

        return substr(hash('xxh128', implode('|', $empreintes)), 0, 12);
    }
}
