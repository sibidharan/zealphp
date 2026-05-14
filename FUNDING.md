# Funding & Research Objectives

ZealPHP is open-source infrastructure for the PHP web. PHP powers 77% of websites globally, but its traditional request-per-process execution model limits real-time, high-concurrency, and streaming use cases. ZealPHP provides a coroutine-native runtime that enables the existing PHP ecosystem to adopt async capabilities without rewriting applications.

## Mission

Enable the massive existing PHP web — including critical European digital infrastructure like Nextcloud, Matomo, and WordPress — to evolve beyond the request-per-process model while preserving backward compatibility and developer familiarity.

## Research Objectives

The following R&D areas represent work that advances the state of the art in PHP runtime architecture:

### 1. Coroutine Isolation Formal Verification
Prove that cross-request data cannot leak between coroutines in ZealPHP's execution model. This is critical for privacy and security in multi-tenant deployments. Deliverable: formal specification of isolation guarantees, automated test suite verifying isolation properties, documentation of the security model.

### 2. Zero-Copy Streaming Primitives
Reduce memory overhead for high-throughput streaming use cases (AI token streaming, SSE, large file transfers). Research optimal buffer management strategies within OpenSwoole's coroutine scheduler. Deliverable: benchmarked streaming primitives with documented memory characteristics.

### 3. Privacy-Preserving Session Architecture
Design and implement a session system where coroutine isolation guarantees prevent session data from being observable across requests. Current PHP session implementations share mutable state at the process level. Deliverable: session driver with formally specified privacy properties.

### 4. Legacy PHP Migration Toolkit
Build static analysis tooling that assesses existing PHP applications for coroutine compatibility — identifying global state mutations, blocking I/O calls, and session assumptions that break under concurrent execution. Deliverable: CLI tool that produces a migration report for any PHP project.

### 5. Federation Protocol Support
Provide WebSocket and SSE primitives optimized for decentralized web protocols (ActivityPub, WebSub). Enable PHP applications to participate in federated networks without additional infrastructure. Deliverable: reference implementation of ActivityPub server endpoints using ZealPHP primitives.

## European Dimension

PHP underpins significant European digital infrastructure:
- **Nextcloud** — privacy-focused file sharing and collaboration (Germany)
- **Matomo** — privacy-respecting web analytics (France)
- **WordPress** — powers 43% of all websites, widely used by European institutions

ZealPHP enables these platforms to adopt async capabilities (real-time collaboration, live updates, streaming) without migrating to non-PHP runtimes, reducing dependency on proprietary cloud services and preserving European digital sovereignty.

## Differentiation

ZealPHP is not duplicating existing work. See [docs/competitive-analysis.md](docs/competitive-analysis.md) for detailed comparison with ReactPHP, AMPHP, FrankenPHP, RoadRunner, and Laravel Octane.

## License

ZealPHP is released under the MIT license, an OSI-approved open-source license.

## Contact

Sibidharan — sibi.nandhu@gmail.com
GitHub: https://github.com/sibidharan/zealphp
