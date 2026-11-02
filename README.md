# Prism OpenTelemetry

OpenTelemetry bridge for [Prism](https://github.com/Particle-Academy/prism). It
subscribes to Prism's neutral telemetry events and turns them into
[GenAI-convention](https://opentelemetry.io/docs/specs/semconv/gen-ai/) spans —
a root span per generation, child spans per step and per tool call, with token,
cost, model, finish-reason and provider rate-limit attributes — exported over OTLP to
[Arize Phoenix](https://github.com/Arize-ai/phoenix) or any OTLP backend.

The bridge supports Prism `>=0.124.1 <1.0`. With core 0.146 or later it also
subscribes to `GenerationErrored`, so an event-signalled stream failure closes
the root span with error status. Registration is guarded when older core versions
do not provide that event.

> **Working on this package?** Read **[`AGENTS.md`](AGENTS.md)** first — the boundary
> this package has to hold, the gates that must be green, and the traps that have
> already caught someone.
> `@link AGENTS.md`

## How it fits together

```
Prism core (events)                 →  this bridge                →  OTLP backend
Events\Telemetry\GenerationStarted     builds root span              Arize Phoenix,
                 \StepCompleted        + child step spans            Jaeger, Tempo,
                 \ToolInvoked          + child tool spans            Grafana, …
                 \GenerationCompleted  ends the root span
                 \GenerationFailed     ends w/ error status
                 \GenerationErrored    ends event-signalled failures (core 0.146+)
```

Prism core never depends on OpenTelemetry. This package owns the
`open-telemetry/*` dependency and the GenAI attribute mapping, so semantic
convention churn is a release of *this* package, not a change to Prism.

## Installation

```bash
composer require particle-academy/prism-opentelemetry

# Provide an OpenTelemetry SDK + an OTLP exporter (transport of your choice):
composer require open-telemetry/sdk open-telemetry/exporter-otlp
```

Enable Prism telemetry (in the Prism config or environment):

```dotenv
PRISM_TELEMETRY_ENABLED=true
```

Point the OpenTelemetry SDK at your collector / Phoenix instance:

```dotenv
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_SERVICE_NAME=my-app
OTEL_TRACES_EXPORTER=otlp
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:6006   # Phoenix OTLP endpoint
```

## Configuration

Failed-stream usage is provisional and covers one provider attempt, excluding
completed steps. `prism.usage.status`, `prism.usage.scope`,
`prism.usage.step_index`, `prism.usage.reported_fields` and
`prism.usage.includes_completed_steps` identify that scope. Only reported numeric
fields are exported; a missing field stays absent and an explicit zero stays zero.
Cache lifetime counts use `prism.usage.cache_write_5m_input_tokens` and
`prism.usage.cache_write_1h_input_tokens`. Numeric usage does not require content
capture. The new error event exports no prompt, generated content, raw provider
diagnostic or invented exception. A subsequent failure event for the same trace
does not create or end a second span.

```bash
php artisan vendor:publish --tag=prism-opentelemetry-config
```

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `PRISM_OTEL_ENABLED` | `true` | Subscribe the span-building listener. |
| `tracer_name` | `PRISM_OTEL_TRACER_NAME` | `prism` | Instrumentation scope name. |
| `record_exceptions` | `PRISM_OTEL_RECORD_EXCEPTIONS` | `true` | Record the exception on failed spans. |

## Privacy

**By default, spans carry metadata only**: tokens, timing, model, finish reason
and provider rate limits. Prompts, completions, tool arguments and tool results
are added as `input.value` / `output.value` **only when Prism's
`prism.telemetry.capture_content` is on**, and it is off by default. Each
captured attribute is cut to `content_max_length`.

**Attachment bytes are withheld even with content capture on.** A captured
message's images, documents, audio and video keep their kind, mime type, file id
and filename, and their `base64` is replaced by `omitted_bytes`, the size of what
was left out. To export the bytes as well, set Prism's
`prism.telemetry.capture_media` (`PRISM_TELEMETRY_CAPTURE_MEDIA=true`). Bytes a
user pastes into a prompt as text are text, and are exported with the prompt.

## License

MIT © Particle Academy
