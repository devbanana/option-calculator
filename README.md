# option-calculator

This console application allows you to fetch options data, calculate various options strategies, and even optionally make live trades.

Note that this is a work in progress, so while I can guarantee the master branch works, I can't guarantee it is bug-free. Class interfaces could change without notice up until the first official release.

## Available Commands

* get:quote — Gets quotes for stocks or options. Just pass the symbol.
* list:expirations — List the option expirations for a given underlying symbol.
* list:chains — List the option chains for the given symbol and expiration date.
* calculate:adx — Calculates the average directional index (including directional movement indicators) and returns them in a tabular format for the last 30 days.
* calculate:rsi — Calculates the relative strength index for the given symbol for the last 30 days.
* trade:create — Creates a new trade. It'll guide you through the process of choosing your stock and making the trade. Requires a brokerage account with [Tradier](https://brokerage.tradier.com/).
* trade:modify — Modifies an existing order.
* trade:status — Checks the status of an existing order.
* account:positions — Lists all positions in your account.
* research:price-target — Gets analyst price targets on a given stock. Requires a premium subscription with IEX.

## API Keys

At a minimum, you will need a developer API key from [Tradier](https://developer.tradier.com). It is free and you can get a sandbox API key instantly. YOu don't need a brokerage account with Tradier to get market data. Only the trading and account commands require a Tradier brokerage account.

Some research commands also require the [IEX Cloud API](https://iexcloud.io). Certain API calls are free with IEX, but more advanced data require a paid account.

Currently the following commands require a premium account:

* research:price-target

## Support and Contributing

If you have any questions, feel free to open a support ticket. I'm also happy to consider suggested features or even a pull request should you be so inclined.
