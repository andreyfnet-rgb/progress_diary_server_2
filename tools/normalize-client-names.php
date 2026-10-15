<?php

declare(strict_types=1);

/**
 * One-off: rewrites ALL-CAPS Аклуб/FoxPro client names ("ПЕТРАЧЕНКО ЛАРИСА
 * БОРИСОВНА") into the "Фамилия Имя Отчество" casing 1C uses. See
 * NameFormatting::fromAllCaps for exactly what is and isn't touched.
 *
 * Dry run by default -- prints what would change and writes nothing.
 *
 * Usage: php tools/normalize-client-names.php            (preview)
 *        php tools/normalize-client-names.php --apply    (write)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require __DIR__ . '/../src/autoload.php';

use Gdpd\Data\Db;
use Gdpd\Infrastructure\Config;
use Gdpd\Infrastructure\NameFormatting;

$apply = in_array('--apply', $argv, true);

$config = new Config(dirname(__DIR__) . '/config.ini');
$db = new Db(
    $config->get('db', 'host', '127.0.0.1'),
    (int) $config->get('db', 'port', '3306'),
    $config->get('db', 'name'),
    $config->get('db', 'user', 'root'),
    $config->get('db', 'pass', '')
);

$changes = [];
foreach ($db->query('SELECT cln_id, cln_name FROM Client') as $row) {
    $old = (string) $row['cln_name'];
    $new = NameFormatting::fromAllCaps($old);
    if ($new !== $old) {
        $changes[(int) $row['cln_id']] = [$old, $new];
    }
}

foreach (array_slice($changes, 0, 30, true) as $id => [$old, $new]) {
    echo "{$id}: {$old}  ->  {$new}\n";
}
if (count($changes) > 30) {
    echo '... and ' . (count($changes) - 30) . " more\n";
}

if (!$apply) {
    echo "\nPreview only: " . count($changes) . " name(s) would change. Re-run with --apply to write them.\n";
    exit(0);
}

$pdo = $db->pdo();
$pdo->beginTransaction();
$statement = $pdo->prepare('UPDATE Client SET cln_name = ? WHERE cln_id = ?');
foreach ($changes as $id => [, $new]) {
    $statement->execute([$new, $id]);
}
$pdo->commit();

echo "\nUpdated " . count($changes) . " name(s).\n";
