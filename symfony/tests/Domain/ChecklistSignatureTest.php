<?php

declare(strict_types=1);

namespace Merisu\Inventory\Tests\Domain;

use Merisu\Inventory\Domain\ChecklistItem;
use Merisu\Inventory\Domain\ChecklistSignature;
use Merisu\Inventory\Domain\ChecklistStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChecklistSignatureTest extends TestCase
{
    private static function point(bool $photo = false): ChecklistItem
    {
        return new ChecklistItem('p1', 'OPENING', ['fr' => 'Vitrine'], 1, true, true, $photo);
    }

    public function testUneSignatureCompleteEstRecevable(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Done,
            pinRecognised: true,
            note: null,
            photoProvided: false,
        );

        self::assertTrue($verdict->isValid());
        self::assertSame([], $verdict->issues);
    }

    /** Sans code reconnu, la signature ne désigne personne. */
    public function testUnCodeInconnuRefuseLaSignature(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Done,
            pinRecognised: false,
            note: null,
            photoProvided: false,
        );

        self::assertFalse($verdict->isValid());
        self::assertContains('signature.unknownPin', $verdict->issues);
    }

    /**
     * ATTENTE n'est pas un choix mais l'absence de choix : « en attente, signé
     * par Marco à 8 h » n'aurait aucun sens dans l'historique.
     */
    public function testLAttenteNeSeSignePas(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Pending,
            pinRecognised: true,
            note: 'peu importe',
            photoProvided: true,
        );

        self::assertFalse($verdict->isValid());
        self::assertContains('signature.statusRequired', $verdict->issues);
    }

    /** @return iterable<string, array{?string}> */
    public static function motifsVides(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'tabulation et saut de ligne' => ["\t\n "];
    }

    #[DataProvider('motifsVides')]
    public function testUnEchecSansMotifEstRefuse(?string $note): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Failed,
            pinRecognised: true,
            note: $note,
            photoProvided: false,
        );

        self::assertFalse($verdict->isValid());
        self::assertContains('signature.reasonRequired', $verdict->issues);
    }

    public function testUnEchecMotiveEstAccepte(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Failed,
            pinRecognised: true,
            note: 'fuite au groupe',
            photoProvided: false,
        );

        self::assertTrue($verdict->isValid());
    }

    /** « Passer » n'est pas un défaut : aucun motif n'est exigé. */
    public function testPasserNExigeAucunMotif(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(),
            ChecklistStatus::Skipped,
            pinRecognised: true,
            note: null,
            photoProvided: false,
        );

        self::assertTrue($verdict->isValid());
    }

    public function testUnPointAPhotoRefuseUneSignatureSansPhoto(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(photo: true),
            ChecklistStatus::Done,
            pinRecognised: true,
            note: null,
            photoProvided: false,
        );

        self::assertFalse($verdict->isValid());
        self::assertContains('signature.photoRequired', $verdict->issues);
    }

    public function testUnPointAPhotoAccepteAvecLaPhoto(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(photo: true),
            ChecklistStatus::Done,
            pinRecognised: true,
            note: null,
            photoProvided: true,
        );

        self::assertTrue($verdict->isValid());
    }

    /**
     * On ne photographie pas ce qu'on n'a pas fait : l'exigence de photo ne
     * porte que sur un point réellement FAIT.
     *
     * @return iterable<string, array{ChecklistStatus, ?string}>
     */
    public static function statutsSansPhoto(): iterable
    {
        yield 'passé' => [ChecklistStatus::Skipped, null];
        yield 'échec motivé' => [ChecklistStatus::Failed, 'vitrine en panne'];
    }

    #[DataProvider('statutsSansPhoto')]
    public function testLaPhotoNEstPasExigeeQuandLePointNAPasEteFait(
        ChecklistStatus $status,
        ?string $note,
    ): void {
        $verdict = ChecklistSignature::check(
            self::point(photo: true),
            $status,
            pinRecognised: true,
            note: $note,
            photoProvided: false,
        );

        self::assertTrue($verdict->isValid());
    }

    /** Plusieurs manquements se signalent ensemble, pas l'un après l'autre. */
    public function testLesManquementsSeCumulent(): void
    {
        $verdict = ChecklistSignature::check(
            self::point(photo: true),
            ChecklistStatus::Failed,
            pinRecognised: false,
            note: null,
            photoProvided: false,
        );

        self::assertFalse($verdict->isValid());
        self::assertContains('signature.unknownPin', $verdict->issues);
        self::assertContains('signature.reasonRequired', $verdict->issues);
    }
}
