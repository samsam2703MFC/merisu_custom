<?php

declare(strict_types=1);

namespace Merisu\Inventory\Adapter;

/**
 * La traduction assistée n'a pas abouti.
 *
 * Un seul type pour les trois causes — clé absente, service injoignable,
 * réponse inexploitable — parce que la conduite à tenir est la même dans les
 * trois cas : ne RIEN écrire, et le dire. Contrairement à la remontée des
 * comptages, il n'y a ici ni file ni nouvelle tentative : l'administrateur est
 * devant l'écran, et il recliquera lui-même s'il le veut.
 *
 * Le message porte une clé de traduction (`admin.translate.*`), pas une phrase :
 * il est montré à l'écran, dans la langue de l'écran.
 */
final class TranslationUnavailable extends \RuntimeException
{
}
