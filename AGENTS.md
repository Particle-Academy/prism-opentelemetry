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

## This package tracks a branch, not a tag

The Prism-side events it consumes currently live on `feat/telemetry-935` of
`particle-academy/prism`. This bridge follows that branch until the events ship
in a tagged release.

Two consequences worth holding in mind:

- an event shape can change under you between two `composer update`s, and
  nothing in a version constraint will warn you;
- **when the events land in a tagged Prism release, the README's status note and
  the constraint both change in the same commit.** A status note that still says
  "under active development" after the thing shipped is the most common way a
  package misleads its own users.

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

## Known gap in this repo

**There are no CI workflows and no `composer` script aliases here** — unlike
every other PHP package in the ecosystem, which run `test` / `types` / `format`
as four CI jobs.

That is a gap, not a decision. Until it is closed, run the tools directly:

```sh
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
```

If you are working in this repo anyway, closing that gap is worth more than most
feature work you could do here: a telemetry bridge that can leak prompt content
is exactly the kind of package whose guards should be enforced by a machine
rather than by whoever remembered.
