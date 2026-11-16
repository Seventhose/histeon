<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed
			unset( $domain );
			return $text;
		}
	}
}

namespace XB\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use XB\Updates\Minisign;

	/** Minisign signatures, checked with libsodium (docs/spec/updates.md). */
	final class MinisignTest extends TestCase {

		private string $dir;
		/** @var non-empty-string */
		private string $pair;
		private string $id;

		protected function setUp(): void {
			$this->dir  = sys_get_temp_dir() . '/xb-minisign-' . bin2hex( random_bytes( 4 ) );
			mkdir( $this->dir );
			$this->pair = sodium_crypto_sign_keypair();
			$this->id   = random_bytes( 8 );
		}

		protected function tearDown(): void {
			array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() );
			rmdir( $this->dir );
		}

		/** @param non-empty-string|null $pair */
		private function public_key( ?string $pair = null, ?string $id = null ): string {
			return "untrusted comment: test\n" . base64_encode( 'Ed' . ( $id ?? $this->id ) . sodium_crypto_sign_publickey( $pair ?? $this->pair ) ) . "\n";
		}

		/** A signature as minisign writes it (prehashed `ED`, or legacy `Ed`). */
		private function sign( string $file, string $comment = "timestamp:1\tfile:xb-1.0.0.zip\thashed", string $alg = 'ED' ): string {
			$data = (string) file_get_contents( $file );
			$sk   = sodium_crypto_sign_secretkey( $this->pair );
			$sig  = sodium_crypto_sign_detached( 'ED' === $alg ? sodium_crypto_generichash( $data, '', 64 ) : $data, $sk );
			return "untrusted comment: firma\n" . base64_encode( $alg . $this->id . $sig ) . "\ntrusted comment: $comment\n" . base64_encode( sodium_crypto_sign_detached( $sig . $comment, $sk ) ) . "\n";
		}

		private function file( string $content ): string {
			$path = $this->dir . '/xb-1.0.0.zip';
			file_put_contents( $path, $content );
			return $path;
		}

		public function test_a_good_signature_verifies_and_gives_its_trusted_comment(): void {
			$file = $this->file( random_bytes( 200000 ) );
			$this->assertSame( "timestamp:1\tfile:xb-1.0.0.zip\thashed", Minisign::verify( $file, $this->sign( $file ), $this->public_key() ) );
			$this->assertSame( "timestamp:1\tfile:xb-1.0.0.zip", Minisign::verify( $file, $this->sign( $file, "timestamp:1\tfile:xb-1.0.0.zip", 'Ed' ), $this->public_key() ), 'legacy, not prehashed' );
			$this->assertSame( sodium_crypto_generichash( (string) file_get_contents( $file ), '', 64 ), Minisign::blake2b( $file ), 'streamed hash = whole hash' );
		}

		/** @return array<string, array{0: string}> */
		public static function refusals(): array {
			return array(
				'changed file'     => array( 'file' ),
				'other key'        => array( 'key' ),
				'other key id'     => array( 'id' ),
				'changed comment'  => array( 'comment' ),
				'not a signature'  => array( 'garbage' ),
				'unknown algorithm' => array( 'alg' ),
			);
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
		public function test_anything_else_is_refused( string $case ): void {
			$file      = $this->file( 'pacchetto' );
			$signature = $this->sign( $file );
			$key       = $this->public_key();
			switch ( $case ) {
				case 'file':
					file_put_contents( $file, 'pacchetto!' );
					break;
				case 'key':
					$key = $this->public_key( sodium_crypto_sign_keypair() );
					break;
				case 'id':
					$key = $this->public_key( null, random_bytes( 8 ) );
					break;
				case 'comment':
					$signature = str_replace( 'hashed', 'hashed version:9.9.9', $signature );
					break;
				case 'garbage':
					$signature = "untrusted comment: x\nnon base64\n";
					break;
				case 'alg':
					$lines     = explode( "\n", $signature );
					$lines[1]  = base64_encode( 'XX' . substr( (string) base64_decode( $lines[1] ), 2 ) );
					$signature = implode( "\n", $lines );
					break;
			}
			$this->expectException( \InvalidArgumentException::class );
			Minisign::verify( $file, $signature, $key );
		}

		public function test_public_keys_are_checked(): void {
			$this->expectException( \InvalidArgumentException::class );
			Minisign::public_key( 'RWQnon una chiave' );
		}
	}
}
