<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Google Contact Sync — FreePBX module
 *
 * Copyright (C) 2026 Dr. Patrick Maier, Softwareentwicklung Patrick Maier
 * https://www.se-pm.de — mail@se-pm.de
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace FreePBX\modules\Googlecontactsync\Lib;

/**
 * Authenticated, symmetric encryption helper for tokens and secrets at rest.
 *
 * Uses libsodium's `crypto_secretbox` (XSalsa20-Poly1305): every ciphertext is
 * authenticated, so tampering (or an incorrect key) is detected on decrypt and
 * surfaced as a failure rather than corrupted plaintext.
 *
 * The 256-bit key lives in a single key file with `0600` permissions, created
 * atomically on first use. Callers should place that file outside the web root
 * (e.g. under ASTETCDIR). A raw key may be injected instead — useful for tests.
 */
class TokenStore {

	/** Encryption key length in bytes (256-bit). */
	const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

	/** Per-message nonce length in bytes. */
	const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

	/** Poly1305 authentication tag length in bytes. */
	const MAC_BYTES = SODIUM_CRYPTO_SECRETBOX_MACBYTES;

	/** @var string|null Absolute path to the key file (null when a raw key is used). */
	private $keyFile;

	/** @var string|null Lazily-resolved raw 32-byte key. */
	private $key;

	/**
	 * @param string|null $keyFile Absolute path to the key file (created if missing).
	 * @param string|null $rawKey  Optional raw 32-byte key (bypasses the key file).
	 *
	 * @throws \InvalidArgumentException When neither a key file nor a valid raw key is given.
	 */
	public function __construct($keyFile = null, $rawKey = null) {
		if ($rawKey !== null) {
			if (!is_string($rawKey) || strlen($rawKey) !== self::KEY_BYTES) {
				throw new \InvalidArgumentException('Raw key must be exactly ' . self::KEY_BYTES . ' bytes');
			}
			$this->key = $rawKey;
		} elseif (!empty($keyFile)) {
			$this->keyFile = $keyFile;
		} else {
			throw new \InvalidArgumentException('TokenStore requires a key file path or a raw key');
		}
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext
	 * @return string Base64 of (nonce || ciphertext), safe to store in a text column.
	 */
	public function encrypt($plaintext) {
		$key   = $this->getKey();
		$nonce = random_bytes(self::NONCE_BYTES);
		$cipher = sodium_crypto_secretbox((string) $plaintext, $nonce, $key);
		return base64_encode($nonce . $cipher);
	}

	/**
	 * Decrypt a value produced by {@see encrypt()}.
	 *
	 * Returns null (never a partial/garbage string) when the input is empty,
	 * malformed, truncated, tampered with, or encrypted under a different key.
	 *
	 * @param string $encoded
	 * @return string|null
	 */
	public function decrypt($encoded) {
		if (!is_string($encoded) || $encoded === '') {
			return null;
		}
		$raw = base64_decode($encoded, true);
		if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::MAC_BYTES) {
			return null;
		}
		$nonce  = substr($raw, 0, self::NONCE_BYTES);
		$cipher = substr($raw, self::NONCE_BYTES);
		$plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->getKey());
		return ($plain === false) ? null : $plain;
	}

	/**
	 * Resolve the raw key, loading or creating the key file on first use.
	 *
	 * @return string 32-byte key.
	 */
	private function getKey() {
		if ($this->key !== null) {
			return $this->key;
		}

		if (is_file($this->keyFile)) {
			$raw = file_get_contents($this->keyFile);
			if ($raw === false) {
				throw new \RuntimeException('Unable to read encryption key file: ' . $this->keyFile);
			}
			if (strlen($raw) !== self::KEY_BYTES) {
				// Refuse to silently regenerate: that would orphan every value
				// already encrypted with the previous key.
				throw new \RuntimeException('Invalid encryption key length in ' . $this->keyFile);
			}
			$this->key = $raw;
			return $this->key;
		}

		$this->key = $this->createKeyFile();
		return $this->key;
	}

	/**
	 * Atomically create the key file with a fresh random key and `0600` perms.
	 *
	 * @return string The newly generated 32-byte key.
	 */
	private function createKeyFile() {
		$dir = dirname($this->keyFile);
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new \RuntimeException('Unable to create key directory: ' . $dir);
		}

		$key = random_bytes(self::KEY_BYTES);
		$tmp = $this->keyFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

		// Create the temp file with restrictive permissions before writing the key.
		$fh = @fopen($tmp, 'xb');
		if ($fh === false) {
			throw new \RuntimeException('Unable to create temporary key file in: ' . $dir);
		}
		@chmod($tmp, 0600);
		if (fwrite($fh, $key) !== self::KEY_BYTES) {
			fclose($fh);
			@unlink($tmp);
			throw new \RuntimeException('Failed to write encryption key');
		}
		fclose($fh);

		if (!@rename($tmp, $this->keyFile)) {
			@unlink($tmp);
			// Another process may have just created it; adopt its key if valid.
			if (is_file($this->keyFile)) {
				$raw = file_get_contents($this->keyFile);
				if ($raw !== false && strlen($raw) === self::KEY_BYTES) {
					return $raw;
				}
			}
			throw new \RuntimeException('Failed to persist encryption key to: ' . $this->keyFile);
		}
		@chmod($this->keyFile, 0600);

		return $key;
	}
}
