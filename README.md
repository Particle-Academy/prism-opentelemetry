# Prism OpenTelemetry

OpenTelemetry bridge for [Prism](https://github.com/Particle-Academy/prism). It
subscribes to Prism's neutral telemetry events and turns them into
[GenAI-convention](https://opentelemetry.io/docs/specs/semconv/gen-ai/) spans —
a root span per generation, child spans per step and per tool call, with token,
cost, model, and finish-reason attributes — exported over OTLP to
[Arize Phoenix](https://github.com/Arize-ai/phoenix) or any OTLP backend.

> **Status: under active development.** The Prism-side telemetry events this
> package consumes currently live on the `feat/telemetry-935` branch of
> `particle-academy/prism`; this bridge tracks that branch until the events ship
> in a tagged Prism release.

## How it fits together

```
Prism core (events)                 →  this bridge                →  OTLP backend
Events\Telemetry\GenerationStarted     builds root span              Arize Phoenix,
                 \StepCompleted        + child step spans            Jaeger, Tempo,
                 \ToolInvoked          + child tool spans            Grafana, …
                 \GenerationCompleted  ends the root span
                 \GenerationFailed     ends w/ error status
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

```bash
php artisan vendor:publish --tag=prism-opentelemetry-config
```

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `enabled` | `PRISM_OTEL_ENABLED` | `true` | Subscribe the span-building listener. |
| `tracer_name` | `PRISM_OTEL_TRACER_NAME` | `prism` | Instrumentation scope name. |
| `record_exceptions` | `PRISM_OTEL_RECORD_EXCEPTIONS` | `true` | Record the exception on failed spans. |

## Privacy

This bridge only reads span metadata (tokens, timing, model, finish reason). It
never adds prompt or completion text to spans. Prism's own
`prism.telemetry.capture_content` flag governs whether that content is present on
the events at all, and it is off by default.

## License

MIT © Particle Academy
