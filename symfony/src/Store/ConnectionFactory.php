<?php

declare(strict_types=1);

namespace Merisu\Inventory\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Ouvre la connexion à partir de `DATABASE_URL`.
 *
 * DBAL 4 n'accepte plus la clé `url` : la chaîne de connexion doit être
 * convertie en paramètres par `DsnParser`.
 *
 * Deux moteurs sont prévus :
 *   · `sqlite:///…`  — démonstration et tests, sans rien installer ;
 *   · `postgresql://…` — production.
 */
final class ConnectionFactory
{
    private const SCHEMES = [
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'mysql' => 'pdo_mysql',
    ];

    public static function create(string $databaseUrl): Connection
    {
        $params = (new DsnParser(self::SCHEMES))->parse($databaseUrl);

        // SQLite : le fichier doit exister dans un dossier accessible en écriture.
        if (($params['driver'] ?? '') === 'pdo_sqlite' && isset($params['path'])) {
            $directory = \dirname((string) $params['path']);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Dossier de base de données inaccessible : %s', $directory));
            }
        }

        return DriverManager::getConnection($params);
    }
}
