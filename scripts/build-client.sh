#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")/.."
mkdir -p dist
cd client
for pair in linux/amd64 linux/arm64 linux/arm darwin/amd64 darwin/arm64 windows/amd64 windows/arm64; do
  os=${pair%/*}; arch=${pair#*/}; suffix=''; flags='-s -w'
  if [ "$os" = windows ]; then suffix=.exe; flags="$flags -H=windowsgui"; fi
  CGO_ENABLED=0 GOOS="$os" GOARCH="$arch" GOARM=6 go build -trimpath -ldflags "$flags" -o "../dist/kienzle-sumup-$os-$arch$suffix" .
done
cd ../dist
if command -v sha256sum >/dev/null; then sha256sum kienzle-sumup-* > SHA256SUMS; else shasum -a 256 kienzle-sumup-* > SHA256SUMS; fi
