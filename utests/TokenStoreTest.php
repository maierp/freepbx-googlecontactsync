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

use PHPUnit\Framework\TestCase;
use FreePBX\modules\Googlecontactsync\Lib\TokenStore;

/**
 * @covers \FreePBX\modules\Googlecontactsync\Lib\TokenStore
 */
class TokenStoreTest extends TestCase {

	/** @var string[] Key files created during a test, removed in tearDown. */
	private $tmpFiles = array();

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
		$this->tmpFiles = array();
	}

	private function tmpKeyPath(): string {
		$path = sys_get_temp_dir().'/gcs_tokenstore_'.bin2hex(random_bytes(8)).'.key';
		$this->tmpFiles[] = $path;
		return $path;
	}

	public function testEncryptDecryptRoundTrip(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$plain = 'a-very-secret-oauth-client-secret';
		$cipher = $store->encrypt($plain);

		$this->assertNotSame($plain, $cipher, 'Ciphertext must differ from plaintext');
		$this->assertSame($plain, $store->decrypt($cipher), 'Decrypt must recover the original plaintext');
	}

	public function testRoundTripWithBinaryAndUnicode(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$plain = "äöü-\x00\x01\xff-emoji-\u{1F510}";
		$this->assertSame($plain, $store->decrypt($store->encrypt($plain)));
	}

	public function testRoundTripWithEmptyString(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$cipher = $store->encrypt('');
		$this->assertSame('', $store->decrypt($cipher));
	}

	public function testEncryptUsesRandomNoncePerCall(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$plain = 'same-input';
		$this->assertNotSame(
			$store->encrypt($plain),
			$store->encrypt($plain),
			'Two encryptions of the same value must differ (random nonce)'
		);
	}

	public function testKeyFileIsCreatedWithRestrictivePermissions(): void {
		$path = $this->tmpKeyPath();
		$store = new TokenStore($path);
		$store->encrypt('trigger key creation');

		$this->assertFileExists($path);
		$this->assertSame(TokenStore::KEY_BYTES, strlen(file_get_contents($path)), 'Key file must hold a 32-byte key');
		clearstatcache();
		$this->assertSame('0600', substr(sprintf('%o', fileperms($path)), -4), 'Key file must be chmod 0600');
	}

	public function testKeyIsPersistedAndReusedAcrossInstances(): void {
		$path = $this->tmpKeyPath();
		$cipher = (new TokenStore($path))->encrypt('persisted-secret');
		// A fresh instance reading the same key file must decrypt prior ciphertext.
		$this->assertSame('persisted-secret', (new TokenStore($path))->decrypt($cipher));
	}

	public function testTamperedCiphertextReturnsNull(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$cipher = $store->encrypt('tamper-me');
		$raw = base64_decode($cipher);
		$raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
		$this->assertNull($store->decrypt(base64_encode($raw)), 'Tampered ciphertext must fail authentication');
	}

	public function testDecryptWithDifferentKeyReturnsNull(): void {
		$cipherFromA = (new TokenStore($this->tmpKeyPath()))->encrypt('cross-key');
		$storeB = new TokenStore(null, random_bytes(TokenStore::KEY_BYTES));
		$this->assertNull($storeB->decrypt($cipherFromA), 'Decrypt under a different key must fail');
	}

	public function testDecryptRejectsMalformedInput(): void {
		$store = new TokenStore($this->tmpKeyPath());
		$this->assertNull($store->decrypt(''));
		$this->assertNull($store->decrypt('not-base64-$$$'));
		$this->assertNull($store->decrypt(base64_encode('too-short')));
	}

	public function testRawKeyConstructorRoundTrip(): void {
		$key = random_bytes(TokenStore::KEY_BYTES);
		$enc = (new TokenStore(null, $key))->encrypt('with-injected-key');
		// Same raw key in a separate instance decrypts it.
		$this->assertSame('with-injected-key', (new TokenStore(null, $key))->decrypt($enc));
	}

	public function testInvalidRawKeyLengthThrows(): void {
		$this->expectException(\InvalidArgumentException::class);
		new TokenStore(null, 'too-short-key');
	}

	public function testConstructorRequiresKeyFileOrRawKey(): void {
		$this->expectException(\InvalidArgumentException::class);
		new TokenStore();
	}

	public function testCorruptKeyFileLengthThrows(): void {
		$path = $this->tmpKeyPath();
		file_put_contents($path, 'short'); // not 32 bytes
		$store = new TokenStore($path);
		$this->expectException(\RuntimeException::class);
		$store->encrypt('boom');
	}
}
