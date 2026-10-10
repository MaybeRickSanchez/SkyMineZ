<?php

declare(strict_types=1);

namespace AM\SkyMineZ\economy;

/**
 * The soft currency shown as "MONEY" on the sidebar. Backed by
 * plugin_data/money_economy.json.
 */
final class MoneyEconomy extends BaseEconomy
{
    protected string $type = 'money';
}