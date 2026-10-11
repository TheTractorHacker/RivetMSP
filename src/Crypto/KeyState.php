<?php

declare(strict_types=1);

namespace RivetMSP\Crypto;

use RivetCore\Crypto\KeyRing;

/** What {@see KeyStore::load()} found. */
final class KeyState
{
    /**
     * @param 'file'|'file-error'|'legacy'|'legacy-error'|'none' $source
     * @param list<string> $warnings permission findings of the key file
     */
    public function __construct(
        public readonly KeyRing $ring,
        public readonly string $source,
        public readonly ?string $path,
        public readonly array $warnings,
        public readonly ?string $error,
        /** Set when a key file may exist but this process cannot reach it (a directory it cannot traverse): the source then says "legacy" for the wrong reason. */
        public readonly ?string $unreachable = null,
    ) {
    }

    public function usable(): bool
    {
        return $this->error === null && $this->ring->hasActive();
    }

    public function fromFile(): bool
    {
        return $this->source === 'file' || $this->source === 'file-error';
    }

    public function __debugInfo(): array
    {
        return ['source' => $this->source, 'kids' => $this->ring->kids(), 'error' => $this->error];
    }
}
