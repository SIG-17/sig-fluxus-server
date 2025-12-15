#!/usr/local/bin/php
<?php
declare(strict_types=1);

use Tabula17\Satelles\Omnia\Roga\Loader\XmlFile;
use Tabula17\Satelles\Utilis\Cache\RedisStorage;

require __DIR__ . '/../vendor/autoload.php';



$xmlDir = dirname(__DIR__) . '/db/xml';

//$redisConfig = $redisConfigs['db-cache'];
$loader = new XmlFile(
    baseDir: $xmlDir
);



$directoryIterator = new RecursiveDirectoryIterator($xmlDir);
$recursiveIterator = new RecursiveIteratorIterator($directoryIterator);

// Filter for files ending with .xml (case-insensitive)
$xmlFiles = new RegexIterator(
    $recursiveIterator,
    '/\.xml$/i',
    RegexIterator::MATCH
);

echo "XML files found:\n";
foreach ($xmlFiles as $file) {
    echo str_replace([$xmlDir.DIRECTORY_SEPARATOR, '.xml'], '', $file->getPathname()).' -> '. $file->getPathname() . "\n";
}