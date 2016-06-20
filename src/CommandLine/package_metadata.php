<?php
declare(strict_types=1);

use \Airship\Alerts\Hail\NoAPIResponse;
use \Airship\Engine\{
    Database,
    Hail,
    State
};
use \ParagonIE\Halite\Asymmetric\SignaturePublicKey;

require_once \dirname(__DIR__).'/bootstrap.php';

function processUpdates(Database $db, array $updates = []): bool
{
    $db->beginTransaction();
    foreach ($updates as $update) {
        $db->update(
            'airship_package_cache',
            [
                'skyport_metadata' => \json_encode($update['metadata'])
            ],
            [
                'packagetype' => $update['package']['type'],
                'supplier' => $update['package']['supplier'],
                'name' => $update['package']['name']
            ]
        );
    }
    return $db->commit();
}

$channels = \Airship\loadJSON(ROOT . '/config/channels.json');
$state = State::instance();
$lastScan = \file_get_contents(ROOT . '/tmp/last_metadata_update.txt');
if ($lastScan === false) {
    $lastScan = '1970-01-01\T00:00:00';
}
$db = \Airship\get_database();

if ($state->hail instanceof Hail) {
    foreach ($channels as $identifier => $channel) {
        $publicKey = new SignaturePublicKey(
            \Sodium\hex2bin($channel['public_key'])
        );

        foreach ($channel['urls'] as $url) {
            try {
                $updates = $state->hail->postSignedJSON(
                    $url . '/packageMeta',
                    $publicKey,
                    [
                        'since' => $lastScan
                    ]
                );
                if ($updates['status'] === 'success') {
                    if (\processUpdates($db, ['packageMetadata'])) {
                        file_put_contents(
                            ROOT . '/tmp/last_metadata_update.txt',
                            (new \DateTime())->format('Y-m-d\TH:i:s')
                        );
                        exit(0);
                    }
                }
            } catch (NoAPIResponse $ex) {
            }
        }
    }
}
exit(255);
