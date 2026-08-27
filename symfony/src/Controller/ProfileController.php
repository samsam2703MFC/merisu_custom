<?php

declare(strict_types=1);

namespace Merisu\Inventory\Controller;

use Merisu\Inventory\Adapter\ConsultantServiceInterface;
use Merisu\Inventory\Security\CurrentUser;
use Merisu\Inventory\Store\ShopStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Fiche profil du consultant connecté.
 *
 * Toutes les informations affichées appartiennent au module Consultant
 * existant : cet écran les présente, il ne les modifie pas. Une éventuelle
 * modification devra se faire dans le module d'origine, sans quoi les deux
 * référentiels divergeraient.
 */
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly ConsultantServiceInterface $consultants,
        private readonly ShopStore $shops,
    ) {
    }

    #[Route('/profil', name: 'profile', methods: ['GET'])]
    public function show(): Response
    {
        $consultant = $this->currentUser->requireConsultant();

        // Postes lisibles : le module existant fournit des identifiants, la
        // fiche doit afficher des noms.
        $names = [];
        foreach ($this->consultants->workstations() as $workstation) {
            $names[$workstation->id] = $workstation->name;
        }

        $assigned = $consultant->workstations !== []
            ? $consultant->workstations
            : array_filter([$consultant->defaultWorkstationId]);

        /*
          Boutiques LISIBLES — même principe que les postes.

          La fiche portait « shop-5 » en clair : l'identifiant interne, qui ne
          désigne rien pour la personne qui se lit. On le rapproche du nom de
          la boutique, par l'identifiant ou par l'ancien texte libre (le champ
          en a longtemps porté). Une valeur qui ne correspond à aucune fiche
          reste affichée telle quelle : un rattachement posé il y a six mois
          doit rester lisible, pas disparaître.
        */
        $boutiques = $this->shops->all();
        $parId = [];
        $parNom = [];
        foreach ($boutiques as $boutique) {
            $parId[$boutique->id] = $boutique->name;
            $parNom[mb_strtolower(trim($boutique->name))] = $boutique->name;
        }

        $shopNames = [];
        foreach ($consultant->shops as $valeur) {
            $texte = trim((string) $valeur);
            if ($texte === '') {
                continue;
            }
            $nom = $parId[$texte] ?? $parNom[mb_strtolower($texte)] ?? $texte;
            if (!\in_array($nom, $shopNames, true)) {
                $shopNames[] = $nom;
            }
        }

        return $this->render('security/profile.html.twig', [
            'consultant' => $consultant,
            'shopNames' => $shopNames,
            'currentWorkstation' => $names[$this->currentUser->workstationId()] ?? $this->currentUser->workstationId(),
            'workstationNames' => array_values(array_map(
                static fn (string $id): string => $names[$id] ?? $id,
                $assigned,
            )),
        ]);
    }
}
