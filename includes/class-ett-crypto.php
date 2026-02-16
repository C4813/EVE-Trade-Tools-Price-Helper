<?php
if (!defined('ABSPATH')) exit;

class ETT_Crypto {

	// Keep key derivation EXACTLY aligned with existing Admin/ExtDB implementations
	private static function enc_key() : string {
		return hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true);
	}

	private static function mac_key() : string {
		return hash('sha256', SECURE_AUTH_KEY . AUTH_KEY, true);
	}

	/**
	 * Encrypt to the plugin's canonical "triplet" format:
	 * ['ciphertext' => base64(raw), 'iv' => base64(iv16), 'mac' => base64(hmac(iv|raw))]
	 */
	public static function encrypt_triplet(string $plaintext) : array {
		if ($plaintext === '') return ['ciphertext' => '', 'iv' => '', 'mac' => ''];

		$iv = random_bytes(16);

		$cipher_raw = openssl_encrypt(
			$plaintext,
			'AES-256-CBC',
			self::enc_key(),
			OPENSSL_RAW_DATA,
			$iv
		);

		if ($cipher_raw === false) {
			throw new Exception('Encryption failed');
		}

		$mac_raw = hash_hmac('sha256', $iv . $cipher_raw, self::mac_key(), true);

		return [
			'ciphertext' => base64_encode($cipher_raw),
			'iv'         => base64_encode($iv),
			'mac'        => base64_encode($mac_raw),
		];
	}

	public static function decrypt_triplet(string $ciphertext, string $iv_b64, string $mac_b64) : string {
		if ($ciphertext === '') return '';

		$iv = base64_decode($iv_b64, true);
		if ($iv === false || strlen($iv) !== 16) return '';

        $cipher_raw = base64_decode($ciphertext, true);
        $mac_raw    = base64_decode($mac_b64, true);
        if ($cipher_raw === false || $mac_raw === false) return '';
        
        $calc = hash_hmac('sha256', $iv . $cipher_raw, self::mac_key(), true);
        if (!hash_equals($calc, $mac_raw)) return '';
        
        $plain = openssl_decrypt(
            $cipher_raw,
            'AES-256-CBC',
            self::enc_key(),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        return $plain === false ? '' : (string)$plain;

	}
}
