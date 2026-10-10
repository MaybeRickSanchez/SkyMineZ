<?php

declare(strict_types=1);

namespace AM\SkyMineZ\economy;

/**
 * The hard currency shown as "GOLD" on the sidebar, also used as the reward for
 * holding an outpost. Backed by plugin_data/gold_economy.json.
 */
final class GoldEconomy extends BaseEconomy
{
    protected string $type = 'gold';
}