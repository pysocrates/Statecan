<?php

namespace App\Receipt;

use App\Entity\CompletionReceipt;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReceiptStorage
{
    public function __construct(#[Autowire('%env(resolve:STATECAN_RECEIPT_DIR)%')] private string $directory) {}

    public function materialize(CompletionReceipt $receipt): string
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create receipt storage directory.');
        }
        $path = $this->path($receipt);
        if (!file_exists($path)) {
            $temp = tempnam($this->directory, '.receipt-');
            if (false === $temp) {
                throw new \RuntimeException('Cannot allocate receipt file.');
            }
            try {
                $handle = fopen($temp, 'wb');
                if (false === $handle) {
                    throw new \RuntimeException('Cannot open receipt file.');
                }
                try {
                    $content = $receipt->getCanonicalContent();
                    $offset = 0;
                    while ($offset < strlen($content)) {
                        $written = fwrite($handle, substr($content, $offset));
                        if (false === $written || 0 === $written) {
                            throw new \RuntimeException('Cannot write receipt file.');
                        }
                        $offset += $written;
                    }
                    if (!fflush($handle) || !fsync($handle)) {
                        throw new \RuntimeException('Cannot synchronize receipt file.');
                    }
                } finally {
                    fclose($handle);
                }
                if (!chmod($temp, 0440)) {
                    throw new \RuntimeException('Cannot set evidence permissions.');
                }
                // link() publishes atomically and never replaces an existing object.
                if (!@link($temp, $path) && !is_file($path)) {
                    throw new \RuntimeException('Cannot publish receipt file.');
                }
                $directory = fopen($this->directory, 'r');
                if (false === $directory) {
                    throw new \RuntimeException('Cannot open evidence directory for synchronization.');
                }
                try {
                    if (!fsync($directory)) {
                        throw new \RuntimeException('Cannot synchronize evidence directory.');
                    }
                } finally {
                    fclose($directory);
                }
            } finally {
                @unlink($temp);
            }
        }

        return $this->verify($receipt);
    }

    public function verify(CompletionReceipt $receipt): string
    {
        $path = $this->path($receipt);
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Receipt object is missing or unsafe.');
        }
        $actual = hash_file('sha256', $path);
        $expected = hash('sha256', $receipt->getCanonicalContent());
        if (false === $actual || !hash_equals($expected, $actual) || ($receipt->getHash() && !hash_equals($receipt->getHash(), $actual))) {
            throw new \RuntimeException('Receipt object hash mismatch.');
        }

        return $actual;
    }

    private function path(CompletionReceipt $receipt): string
    {
        if (!preg_match('/^[a-f0-9]{36}$/D', $receipt->getId())) {
            throw new \InvalidArgumentException('Invalid receipt identifier.');
        }

        return $this->directory.'/'.$receipt->getStorageKey();
    }
}
