<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Template;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le gabarit d'administration ne doit pas voler leurs noms aux écrans.
 *
 * `_layout.html.twig` pose ses variables dans le bloc `content`, et le
 * `admin_content` de chaque écran est rendu DEDANS : tout nom défini là masque
 * la variable de même nom venue du contrôleur.
 *
 * Ce n'est pas une hypothèse. La liste du menu s'est appelée `sections`, et
 * l'écran de check-list — dont le contrôleur passe lui aussi `sections`, ses
 * trois volets — s'est mis à parcourir les familles du menu. Résultat : « Key
 * "value" for sequence/mapping with keys "title, links" », une page 500. Aucun
 * test ne l'a vue, parce qu'aucun ne rend de gabarit ; elle a été découverte
 * en auditant les pages au navigateur.
 *
 * D'où ce garde-fou, qui lit les gabarits comme des données : il n'a besoin ni
 * de Twig, ni d'un noyau, ni d'un navigateur.
 */
final class AdminLayoutShadowingTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../templates/admin/_layout.html.twig';
    private const DOSSIER = __DIR__ . '/../../templates/admin';

    /**
     * Les noms que le gabarit pose, et qui masqueraient donc ceux d'un écran.
     *
     * @return list<string>
     */
    private static function nomsPosesParLeGabarit(): array
    {
        $source = file_get_contents(self::LAYOUT);
        self::assertIsString($source, 'Gabarit d’administration introuvable.');

        $noms = [];

        // {% set truc = ... %} — y compris la forme {%- set … -%}.
        preg_match_all('/\{%-?\s*set\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $m);
        $noms = array_merge($noms, $m[1]);

        // {% for chose in ... %} — la variable de boucle vit dans le bloc.
        preg_match_all('/\{%-?\s*for\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*,\s*([A-Za-z_][A-Za-z0-9_]*))?\s+in\b/', $source, $m);
        $noms = array_merge($noms, $m[1], array_filter($m[2]));

        return array_values(array_unique($noms));
    }

    /** @return iterable<string, array{string}> */
    public static function ecransAdmin(): iterable
    {
        foreach (glob(self::DOSSIER . '/*.twig') ?: [] as $chemin) {
            $nom = basename($chemin);
            if (str_starts_with($nom, '_')) {
                continue;   // fragments inclus, pas des écrans
            }

            if (!str_contains((string) file_get_contents($chemin), "admin/_layout.html.twig")) {
                continue;
            }

            yield $nom => [$chemin];
        }
    }

    #[DataProvider('ecransAdmin')]
    public function testUnEcranNUtilisePasUnNomQueLeGabaritLuiPrend(string $chemin): void
    {
        $source = (string) file_get_contents($chemin);

        foreach (self::nomsPosesParLeGabarit() as $nom) {
            self::assertDoesNotMatchRegularExpression(
                '/\{\{-?\s*' . preg_quote($nom, '/') . '\b|\{%-?\s*(?:if|for|set)\s[^%]*\b' . preg_quote($nom, '/') . '\b/',
                $source,
                sprintf(
                    'Le gabarit d’administration pose « %s » ; cet écran s’en sert et lira donc '
                    . 'la valeur du gabarit, pas la sienne. Renommer la variable du gabarit '
                    . '(préfixe « nav »), pas celle de l’écran : c’est le gabarit qui empiète.',
                    $nom,
                ),
            );
        }
    }

    /**
     * Le préfixe n'est utile que s'il tient : sans cette assertion, un `set`
     * ajouté demain sous un nom courant repartirait pour un tour.
     */
    public function testLeGabaritPrefixeToutesSesVariables(): void
    {
        foreach (self::nomsPosesParLeGabarit() as $nom) {
            self::assertStringStartsWith(
                'nav',
                $nom,
                sprintf(
                    'La variable « %s » du gabarit d’administration n’est pas préfixée : elle '
                    . 'masquera la variable de même nom de tout écran qui en passe une.',
                    $nom,
                ),
            );
        }
    }
}
