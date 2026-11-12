-- Gives user 1 the Noah asteroid skin from the Shard Shop for free: paste it into phpMyAdmin's SQL tab and run it.
--
-- Shop skins are kept in 'bought' in the Ore Mining Inc. save (saveState in HBHub-MinerSaves, see hbHubMinerNewState()
-- in src/assets/php/games/spaceOreMiner/miner.php), so this adds 'noah' there. When the player is in an Anomaly right
-- now, its own copy of the game ($.anomaly.state) gets it too, so it shows up straight away in there as well. Running
-- it twice does nothing the second time, and no Star Shards are taken. Change @userId to give it to someone else.
--
-- Reload the game's tab afterwards: the tab doesn't know until it loads the save again. It's picked in the game like
-- any other skin. Not part of the site: tools/ isn't uploaded.

SET @userId = 1;

UPDATE `HBHub-MinerSaves`
SET saveState = JSON_ARRAY_APPEND(saveState, '$.bought', 'noah')
WHERE userId = @userId AND NOT JSON_CONTAINS(saveState->'$.bought', '"noah"');

UPDATE `HBHub-MinerSaves`
SET saveState = JSON_ARRAY_APPEND(saveState, '$.anomaly.state.bought', 'noah')
WHERE userId = @userId AND JSON_TYPE(saveState->'$.anomaly') = 'OBJECT'
    AND NOT JSON_CONTAINS(saveState->'$.anomaly.state.bought', '"noah"');

-- Should list noah now (no row means that user has never played)
SELECT userId, saveState->'$.bought' AS bought FROM `HBHub-MinerSaves` WHERE userId = @userId;
