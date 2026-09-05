# AGENTS.md — particle-academy/prism-opentelemetry

Prism's neutral telemetry events, turned into GenAI-convention spans for Arize
Phoenix and any OTLP backend.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## The direction of the dependency is the whole design

**Prism core never depends on OpenTelemetry.** Core emits neutral telemetry
events; this package owns the `open-telemetry/*` dependency and the entire GenAI
attribute mapping.

That is what makes semantic-convention churn — and the GenAI conventions churn
often — a release of *this* package rather than a change to core. The moment a
convention detail leaks back into core, eighteen providers inherit it.

So when a mapping is awkward, the fix is here. Adding an attribute to an event
in core because it would be convenient to read here is the boundary inverting.

## The constraint is ahead of the tags, deliberately

`particle-academy/prism` is required at `>=0.116 <1.0`, and **v0.116.0 does not
exist yet**. The bridge reads `GenerationCompleted::$rateLimits`, a field core
gained after v0.115.1, and a `readonly` class has no such property on any
released version — so under `prefer-lowest` (which this repo's test matrix runs)
an older Prism fails loudly rather than silently exporting nothing.

That loud failure is the point, and it is why the constraint moved in the same
commit as the read. The alternative — leaving the range at `>=0.111` and
tolerating a missing field — is the bandaid: it turns a version mismatch into a
span that quietly carries no quota, which is the exact failure mode
(G-45) the field was added to end.

**When Prism v0.116.0 is tagged, nothing here changes**; the constraint is
already correct. What changes is the README's status note, which says the
install is red until then.

Also worth holding in mind: an event shape can change under you between two
`composer update`s, and nothing in a version constraint warns you about a field
whose MEANING moved.

## Span content carries prompts, tool arguments, and PII

This is the risk that distinguishes this package from an ordinary exporter, and
it is the reason to run `security-review` on anything touching
`TelemetrySubscriber` or `Support/`.

**Capture is opt-in, and content must never be emitted when the gate is off.**
There is no acceptable "just for debugging" path that bypasses it — a debug flag
that emits prompt bodies to a collector is a data-exfiltration feature with a
friendly name, and collectors are routinely third-party, long-retained, and
searchable by people who were never in scope for the original data.

The byte cap on captured-content attributes (`0` disables it) is a second bound
behind the gate, not an alternative to it. Opt-in capture must not be able to
bloat a span or the OTLP export.

## The four gates, and why the whitelist has four entries

This repo now runs the same four CI jobs as every other PHP package here:
`tests.yml`, `phpstan.yml`, `formatting.yml`, `require-checker.yml`, plus
`composer test` / `types` / `format`.

**It did not, for a long time, and that is worth knowing rather than forgetting.**
Until 2026-09-04 the only workflow was Factcheck: 32 tests and 80 assertions sat
on disk that CI had never once invoked, Pest and PHPStan and Pint were installed
with nothing calling them, and v0.1.1 went to Packagist on that basis. This file
described the gap accurately the whole time. Nothing enforced it, which is the
actual lesson -- a note is not a check.

**`composer-require-checker.json` whitelists four symbols**, and the reason is
not "they were noisy":

- `Illuminate\Contracts\Events\Dispatcher`
- `Illuminate\Contracts\Support\Arrayable`
- `Illuminate\Support\ServiceProvider`
- `config`

This package requires `illuminate/contracts` and `illuminate/support` narrowly,
rather than the whole framework. But `orchestra/testbench` pulls in
`laravel/framework`, which **`replace`s** both of those packages -- so at check
time they are not installed under their own names and the checker cannot resolve
their symbols. `config` is a helper FUNCTION from an autoloaded `files` entry,
which the checker cannot see either. All four are genuinely declared; only the
resolution fails.

**Do not add to that list to silence a failure.** Adding the checker surfaced
two REAL defects, and they were fixed rather than whitelisted:

- `mb_strcut` (`src/TelemetrySubscriber.php`, the byte-ruler truncation) was used
  with no `ext-mbstring` in `require`. Composer would install happily on a PHP
  without mbstring and the truncation would fatal at runtime.
- `OpenTelemetry\Context\Context` and `ContextInterface` are used directly in
  `src/SpanStore.php` while only `open-telemetry/api` was declared. The symbols
  arrived transitively, so an upstream change to `api`'s own dependencies could
  have removed them without anything here objecting.

Both are now declared. That is the difference the gate buys: a whitelist entry
records a resolution artefact, a new `require` entry records a real dependency.
