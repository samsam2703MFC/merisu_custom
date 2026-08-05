<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\DayNote;
use Merisu\Inventory\Domain\Locale;
use PHPUnit\Framework\TestCase;

/**
 * La note du jour s'affiche en tête du menu : c'est le premier texte que lit le
 * vendeur en arrivant. Une consigne rédigée en français mais lue depuis un
 * poste polonais doit s'afficher en français plutôt que de disparaître — un
 * blanc laisserait croire qu'il n'y a rien à savoir aujourd'hui.
 */
final class DayNoteTest extends TestCase
{
    private function note(array $heading, array $body = []): DayNote
    {
        return new DayNote('note-ciao', $heading, $body, 1, true);
    }

    public function testRendLaLangueDemandee(): void
    {
        $note = $this->note(
            ['fr' => 'Ciao et Grazie', 'pl' => 'Ciao i Grazie'],
            ['fr' => 'À chaque client.', 'pl' => 'Do każdego klienta.'],
        );

        self::assertSame('Ciao i Grazie', $note->headingText(Locale::Pl));
        self::assertSame('Do każdego klienta.', $note->bodyText(Locale::Pl));
    }

    public function testReplieSurLaLangueParDefaut(): void
    {
        $note = $this->note(['fr' => 'Ciao et Grazie'], ['fr' => 'À chaque client.']);

        self::assertSame('Ciao et Grazie', $note->headingText(Locale::Pl, Locale::Fr));
        self::assertSame('À chaque client.', $note->bodyText(Locale::Pl, Locale::Fr));
    }

    public function testReplieSurNimporteQuelleLangueRenseignee(): void
    {
        // Ni la langue du poste ni celle par défaut : plutôt l'italien que rien.
        $note = $this->note(['it' => 'Ciao e Grazie'], ['it' => 'A ogni cliente.']);

        self::assertSame('Ciao e Grazie', $note->headingText(Locale::Pl, Locale::Es));
        self::assertSame('A ogni cliente.', $note->bodyText(Locale::Pl, Locale::Es));
    }

    public function testUnIntertitreVideMontreLIdentifiant(): void
    {
        // Plutôt qu'une ligne vide, incompréhensible au poste : l'identifiant
        // signale à l'administrateur qu'un intertitre manque.
        self::assertSame('note-ciao', $this->note([])->headingText(Locale::Fr));
    }

    public function testUnTexteVideNeMontreRien(): void
    {
        // Le corps, lui, peut légitimement rester vide : un intertitre seul est
        // une consigne valable. Afficher l'identifiant à sa place serait un
        // bruit inexplicable au poste.
        self::assertSame('', $this->note(['fr' => 'Rappel'])->bodyText(Locale::Fr));
    }

    public function testLesBlancsNeComptentPasPourDuTexte(): void
    {
        // Un champ « rempli » d'espaces vient d'un copier-coller malheureux :
        // il ne doit pas court-circuiter le repli sur une langue renseignée.
        $note = $this->note(['pl' => '   ', 'fr' => 'Ciao et Grazie']);

        self::assertSame('Ciao et Grazie', $note->headingText(Locale::Pl));
    }

    public function testUneConsigneEntierementVideEstSignalee(): void
    {
        // Le contrôleur s'en sert : une consigne dont tout a été effacé se
        // supprime, plutôt que de rester en base sans jamais rien afficher.
        self::assertTrue($this->note([], [])->isEmpty());
        self::assertFalse($this->note(['fr' => 'Rappel'])->isEmpty());
        self::assertFalse($this->note([], ['fr' => 'Texte seul'])->isEmpty());
    }

    public function testLesRetoursALaLigneSontConserves(): void
    {
        // « Ciao à l'entrée » et « Grazie au départ » sont deux gestes : les
        // écrire l'un sous l'autre se lit mieux qu'un paragraphe, et le texte
        // saisi doit ressortir tel quel.
        $note = $this->note([], ['fr' => "Ciao à l'entrée.\nGrazie au départ."]);

        self::assertSame("Ciao à l'entrée.\nGrazie au départ.", $note->bodyText(Locale::Fr));
    }
}
