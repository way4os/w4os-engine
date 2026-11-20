<?php

declare(strict_types=1);

namespace OpenSim\Economy;

use OpenSim\Config\GridConfig;

/**
 * What a grid says of its currency: the economy module its simulators run, the key that ties a quote to its purchase, the rate, the
 * tables of the money. BetaGridLikeMoneyModule (the default of OpenSimulator) makes everything free and needs no helper. With any
 * other module, the helpers estimate the cost, relay the transfers and the balance to the module and send the user to its page; the
 * ones the engine knows (Gloebit, Podex) also know its rates and settings.
 */
final class CurrencySettings
{
    /** The key w4os-wp's helpers tie a quote with when the grid has none (a grid without a money server: its economy is free). */
    public const DEFAULT_KEY = '1234567883789';

    /** The packs of Gloebit and what they cost, in cents: the currency estimates the cost of what a viewer asks with them. */
    public const GLOEBIT_PACKS = [400 => 199, 1050 => 499, 2150 => 999, 4500 => 1999, 11500 => 4999];

    /** What the viewer says when it is asked to buy currency with Podex, without a message of the grid (`PodexMessage`). */
    public const PODEX_MESSAGE = 'Please use our terminals in-world to proceed. Click OK to teleport to Podex Exchange area.';

    /** Where the viewer is sent then, without an address of the grid (`PodexUrl`): w4os-wp's. */
    public const PODEX_URL = 'secondlife://Podex Exchange/128/128/21';

    /**
     * @param string $provider the module the engine knows by name (`gloebit`, `podex`), empty for any other
     * @param bool $useMoneyServer a module that is not the beta one keeps the balances and relays the transfers
     * @param string $scriptKey the script key of the MoneyServer (`MoneyScriptAccessKey`); none, DEFAULT_KEY ties the quotes, as w4os-wp's helpers
     * @param string $helperUrl the page of the helpers the viewer shows when it needs help (`currency.php` of the economy)
     * @param float $rate real currency (US cents / 100 as the viewer reads it) for `$ratePer` of the virtual one
     * @param array<int,int> $gloebitPacks
     * @param float $gloebitThreshold 1.0 switches to the next pack even for 1 more, 1.1 or 1.2 keeps the small pack for small differences
     */
    public function __construct(
        public readonly string $provider = '',
        public readonly bool $useMoneyServer = false,
        public readonly string $scriptKey = '',
        public readonly string $helperUrl = '',
        public readonly string $moneyTable = 'balances',
        public readonly string $transactionTable = 'transactions',
        public readonly float $rate = 10.0,
        public readonly float $ratePer = 1000.0,
        public readonly bool $gloebitSandbox = false,
        public readonly array $gloebitPacks = self::GLOEBIT_PACKS,
        public readonly float $gloebitThreshold = 1.2,
        public readonly string $podexMessage = self::PODEX_MESSAGE,
        public readonly string $podexUrl = self::PODEX_URL,
    ) {}

    /**
     * The currency of a grid, from its files: the module is `[Economy] economymodule`, the key `MoneyScriptAccessKey` of `[MoneyServer]`,
     * Gloebit's environment `GLBEnvironment` of `[Gloebit]`. The rate and the settings of a module may be given in `[Helpers]`
     * (`CurrencyRate`, `CurrencyRatePer`, `GloebitConversionThreshold`, `PodexMessage`, `PodexUrl`).
     */
    public static function fromConfig(GridConfig $config, string $helperUrl = ''): self
    {
        $module = strtolower((string) $config->find('Economy', 'economymodule'));
        $key = trim((string) $config->find('MoneyServer', 'MoneyScriptAccessKey'));
        $number = static fn(string $name, float $default): float => is_numeric($config->find('Helpers', $name))
            ? (float) $config->find('Helpers', $name)
            : $default;
        // A text left empty is its default
        $text = static fn(string $name, string $default): string => trim((string) $config->find('Helpers', $name), " \t\"") ?: $default;

        return new self(
            // Podex: the generic money server, the helpers told it is Podex
            provider: in_array($module, ['gloebit', 'podex'], true) ? $module
                : ($module === 'dtlnslmoneymodule' && strtolower(trim((string) $config->find('Helpers', 'CurrencyProvider'))) === 'podex' ? 'podex' : ''),
            useMoneyServer: $module !== '' && $module !== 'betagridlikemoneymodule',
            scriptKey: $key,
            helperUrl: $helperUrl,
            rate: $number('CurrencyRate', 10.0),
            ratePer: $number('CurrencyRatePer', 1000.0),
            gloebitSandbox: strtolower((string) $config->find('Gloebit', 'GLBEnvironment')) === 'sandbox',
            gloebitThreshold: $number('GloebitConversionThreshold', 1.2),
            podexMessage: $text('PodexMessage', self::PODEX_MESSAGE),
            podexUrl: $text('PodexUrl', self::PODEX_URL),
        );
    }

    /** Whether the grid has the key that ties a quote of currency to its purchase. */
    public function hasKey(): bool
    {
        return $this->scriptKey !== '';
    }
}
