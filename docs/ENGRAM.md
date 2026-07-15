# Engram — AI persistent memory in this repository

This repository is prepared to use **Engram** with Cursor via `.cursor/mcp.json`.

## What is Engram?

Engram is an MCP server that gives AI agents persistent memory across sessions. See [Engram documentation](https://www.engram.fyi/docs).

## Repository setup

- **`.cursor/mcp.json`** registers the `engram` MCP server.
- See [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md) for product specs and `REQ-*` traceability.

## Install

```bash
npm install -g @engram-ai/cli
engram --version
```

Restart Cursor after installation.

## References

- [docs/SPEC-KIT.md](SPEC-KIT.md)
- [docs/SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md)
