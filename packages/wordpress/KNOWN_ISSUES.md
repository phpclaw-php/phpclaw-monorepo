# Known Issues

## Plugin.php line coverage - 78.35% (target 80%)

- **Measured:** 2026-09-13 with xdebug, 1,042 tests / 3,216 assertions. Plugin.php is 78.35%
  (456 of 582 lines); the package overall is 87.24% line coverage (8,661 of 9,928 lines).
- **Blocked by:** `__construct()` is bypassed (`newInstanceWithoutConstructor()`), because
  `Plugin::getInstance()` caches into a static with no `reset()`, so it can only ever run once
  per process.
- **Deferred to:** a test-only `reset()` for the constructor.

## McpServerCommand - no unit coverage

- **Blocked by:** `__invoke()` constructs `ToolRegistry`, the engine tools and `StdioTransport`
  inline and then blocks on stdin. Nothing can be substituted and no assertion is reachable.
- **Deferred to:** injecting the server and transport, or splitting the wiring from `serve()`.
