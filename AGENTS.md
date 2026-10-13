# Working on naf/monitoring

Read [composer.json](composer.json), [README.md](README.md), the
[shared workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md) and
[release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the NAF workspace those shared documents are in sibling `docs/`.
Preserve others' work. Code changes use a versioned RC branch, are tested,
committed and pushed, and are merged by the maintainer. Do not publish a release
without a release request. Prepare unreleased user guides in `nafphp/docs/drafts/`
and publish them after verifying the matching release on Packagist. The existing
`docs/monitoring.md` is the source for that first-release guide.

This PHP 8.3+ Composer `naf-plugin` supplies `Naf\Monitoring\monitor()`.
Read `bootstrap.php`, `src/config.php`, `src/Monitor.php`, `src/Metric.php` and
the exporter interfaces before changing behavior. Use NAF configuration,
container registration and existing events. Do not add a parallel framework,
HTTP client, worker or scheduler. Custom HTTP exporters should use PSR-18.

Metric names, types, units and allowed labels are declared centrally. Each
helper call owns its labels; never cache mutable label/request state globally.
Counter increments are deltas. Export batches are detached before sending and
are not retried: UDP delivery has no acknowledgement and retries can overcount.
Exporter failures must not replace application results or exceptions. Diagnostic
callbacks must bypass the monitoring logger to avoid feedback loops.

StatsD and DogStatsD differ: plain StatsD uses encoded name suffixes for labels
and only supports time histograms; DogStatsD supports native tags and histograms.
Preserve explicit units, datagram bounds, wire validation and negative-gauge
reset pairing. UDP endpoints use numeric IP addresses to avoid unbounded DNS.

PSR-3 decoration is optional. Preserve the original logger and context, filter
only the monitoring copy, and reject invalid levels. HTTP hooks must cover the
normal response and shutdown fallback without double counting. Workers flush
after each job; the plugin does not invent queue lifecycle hooks.

Run `composer test` and `composer validate --strict`. No analyse script exists.
Tests include real loopback UDP and a running NAF HTTP host. Never send test data
to external collectors. CI covers PHP 8.3, 8.4 and 8.5; keep runtime syntax and
dependencies compatible with that floor.

Follow the shared [PHP code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
and `.php-cs-fixer.dist.php`. Run `composer style:check`; `composer style:fix` applies the rules.
Keep logical steps and local names readable, preserving public signatures and template output.
