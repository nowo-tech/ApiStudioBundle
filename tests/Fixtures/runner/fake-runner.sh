#!/bin/sh
# Minimal fake sidecar of the ProcessScriptRunner worker test (spec 003, 1.15): answers one run with an unchanged,
# well-formed result. A shell starts in about a millisecond, so 1,000 runs stay fast.
IFS= read -r line
nonce=$(printf '%s' "$line" | sed -n 's/^.*"kind":"run","nonce":"\([0-9a-f]*\)".*$/\1/p')
printf '{"v":1,"kind":"result","nonce":"%s","ok":true,"state":{"variables":{"run":"1"},"dirty":{},"request":{"body":"","headers":{},"queryParams":{}},"response":null,"logs":[],"tests":[],"scopes":{"globals":{},"collection":{},"changes":{"globals":{},"collection":{}}}}}\n' "$nonce"
