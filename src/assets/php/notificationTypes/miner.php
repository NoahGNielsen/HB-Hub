<?php
// Ore Mining Inc. notifications (types "miner.<kind>"). See assets/php/notifications.php.
//
// Nobody did these, so each text is the whole sentence.

return [
    'icon' => '/assets/images/icons/controler.png',
    'kinds' => [
        // A stage of a Cosmic Engine was done, so its perk works for everyone now. Sent to everyone who has made a
        // Warp Jump (hbHubMinerEngineAnnounce() in assets/php/games/spaceOreMiner/engine.php). Data: engine (its
        // number), engineName (missing from Engine #1's first ones: the Cosmic Engine), stage, name and perk (its
        // text, as they were then).
        'engineStage' => [
            'text' => static fn (array $data): string => 'The ' . ($data['engineName'] ?? 'Cosmic Engine') . '\'s ' . ($data['name'] ?? 'next stage') . ' (stage '
                . (int) ($data['stage'] ?? 0) . ') is finished! ' . ($data['perk'] ?? ''),
            'link' => static fn (array $data): string => '/games/ore-mining-inc/?view=starbound',
        ],
    ],
];
