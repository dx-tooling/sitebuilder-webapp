#!/usr/bin/env bash
#MISE description="Check Docker Desktop is configured for optimal performance"
#MISE alias="check-docker"

set -e

if [[ "$OSTYPE" != "darwin"* ]]; then
    echo "✓ Not macOS - Docker Desktop performance check skipped"
    exit 0
fi

# Docker Desktop 4.35+ uses settings-store.json, older versions use settings.json
DOCKER_SETTINGS_NEW="$HOME/Library/Group Containers/group.com.docker/settings-store.json"
DOCKER_SETTINGS_OLD="$HOME/Library/Group Containers/group.com.docker/settings.json"

# Prefer new settings file, fall back to old
if [[ -f "$DOCKER_SETTINGS_NEW" ]]; then
    DOCKER_SETTINGS="$DOCKER_SETTINGS_NEW"
    # New format uses PascalCase keys
    USE_LIBKRUN=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('UseLibkrun', False)).lower())" 2>/dev/null || echo "unknown")
    USE_VIRT_FRAMEWORK=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('UseVirtualizationFramework', False)).lower())" 2>/dev/null || echo "unknown")
    USE_VIRTIO=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('UseVirtualizationFrameworkVirtioFS', False)).lower())" 2>/dev/null || echo "unknown")
elif [[ -f "$DOCKER_SETTINGS_OLD" ]]; then
    DOCKER_SETTINGS="$DOCKER_SETTINGS_OLD"
    # Old format uses camelCase keys
    USE_LIBKRUN=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('useLibkrun', False)).lower())" 2>/dev/null || echo "unknown")
    USE_VIRT_FRAMEWORK=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('useVirtualizationFramework', False)).lower())" 2>/dev/null || echo "unknown")
    USE_VIRTIO=$(python3 -c "import json; d=json.load(open('$DOCKER_SETTINGS')); print(str(d.get('useVirtualizationFrameworkVirtioFS', False)).lower())" 2>/dev/null || echo "unknown")
else
    echo "⚠️  Docker Desktop settings file not found"
    exit 0
fi

# Docker VMM (best performance) = UseLibkrun: true
# Apple Virtualization + VirtioFS (good) = UseVirtualizationFramework: true + VirtioFS: true
# QEMU or no VirtioFS (poor) = everything else

if [[ "$USE_LIBKRUN" == "true" ]]; then
    echo "✓ Docker Desktop is using Docker VMM (optimal performance)"
    exit 0
fi

if [[ "$USE_VIRT_FRAMEWORK" == "true" && "$USE_VIRTIO" == "true" ]]; then
    echo "✓ Docker Desktop is using Apple Virtualization Framework with VirtioFS (good performance)"
    echo ""
    echo "  Tip: For even better I/O performance (up to 9x faster), consider switching to Docker VMM:"
    echo "       Docker Desktop → Settings → General → Virtual Machine Manager → Docker VMM"
    echo "       (Requires Docker Desktop 4.35+, Apple Silicon, no Rosetta support)"
    exit 0
fi

# Suboptimal configuration
echo ""
echo "╔══════════════════════════════════════════════════════════════════════╗"
echo "║  Docker Desktop is not configured for optimal performance!           ║"
echo "║  This will cause severe slowness for PHPStan and other tools.        ║"
echo "╠══════════════════════════════════════════════════════════════════════╣"
echo "║  Recommended: Enable Docker VMM (fastest, ~9x improvement)           ║"
echo "║    Docker Desktop → Settings → General → Virtual Machine Manager     ║"
echo "║    Select 'Docker VMM' (requires Apple Silicon, Docker 4.35+)        ║"
echo "║                                                                      ║"
echo "║  Alternative: Enable Apple Virtualization Framework + VirtioFS       ║"
echo "║    Docker Desktop → Settings → General                               ║"
echo "║    Enable 'Use Virtualization Framework' and 'VirtioFS'              ║"
echo "╚══════════════════════════════════════════════════════════════════════╝"
echo ""
echo "Current settings:"
echo "  UseLibkrun (Docker VMM): $USE_LIBKRUN"
echo "  UseVirtualizationFramework: $USE_VIRT_FRAMEWORK"
echo "  UseVirtualizationFrameworkVirtioFS: $USE_VIRTIO"
echo ""
exit 0
