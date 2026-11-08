<?php
/**
 * Cache and Backup tests.
 *
 * @package Gistful
 */

use Gistful\Backup;
use Gistful\Cache;
use Gistful\Gist_Payload;

/**
 * Keys, TTLs, negative cache, tracking and backup storage.
 */
class Test_Cache_Backup extends Gistful_Test_Case {

	public function test_key_formats() {
		$gen = Cache::generation();
		$this->assertSame( 'gistful_r_' . md5( $gen . '|abc|f.php' ), Cache::raw_key( 'abc', 'f.php' ) );
		$this->assertSame( Cache::raw_key( 'abc', 'f.php' ), Cache::raw_key( 'abc', 'f.php' ), 'deterministic within a generation' );
		$this->assertSame( '_gistful_backup_' . md5( 'abc|' ), Backup::meta_key( 'abc', '' ) );
		$a = array(
			'id'     => 'abc',
			'lines'  => '1',
			'oembed' => false,
		);
		$b = array(
			'oembed' => true,
			'lines'  => '1',
			'id'     => 'abc',
		);
		$this->assertSame( Cache::html_key( $a ), Cache::html_key( $b ), 'oembed ignored, order ignored' );
		$this->assertStringStartsWith( 'gistful_h_', Cache::html_key( $a ) );
		$a['lines'] = '2';
		$this->assertNotSame( Cache::html_key( $a ), Cache::html_key( $b ) );
	}

	public function test_flush_all_bumps_generation_so_old_keys_are_unreachable() {
		$attr = array( 'id' => 'abc' );
		$old  = Cache::html_key( $attr );
		$gen  = Cache::generation();
		set_transient( $old, 'stale', 600 );
		Cache::flush_all();
		$this->assertSame( $gen + 1, Cache::generation() );
		$new = Cache::html_key( $attr );
		$this->assertNotSame( $old, $new );
		// Simulate a persistent object cache that kept the old entry alive.
		set_transient( $old, 'stale', 600 );
		$this->assertFalse( Cache::get( $new ) );
		$this->assertSame( 'gistful_f_' . md5( ( $gen + 1 ) . '|abc' ), Cache::names_key( 'abc' ) );
	}

	public function test_default_ttl_and_filter() {
		Cache::set( 'gistful_x', 'v' );
		$this->assertSame( 'v', Cache::get( 'gistful_x' ) );
		$this->assertEqualsWithDelta( time() + 12 * HOUR_IN_SECONDS, (int) get_option( '_transient_timeout_gistful_x' ), 5 );
		add_filter(
			'gistful_cache_ttl',
			static function () {
				return 60;
			}
		);
		Cache::set( 'gistful_y', 'v' );
		$this->assertEqualsWithDelta( time() + 60, (int) get_option( '_transient_timeout_gistful_y' ), 5 );
	}

	public function test_zero_ttl_stores_nothing() {
		Cache::set( 'gistful_z', 'v', 0 );
		$this->assertFalse( Cache::get( 'gistful_z' ) );
	}

	public function test_negative_cache() {
		$k = Cache::raw_key( 'abc', '' );
		$this->assertFalse( Cache::is_negative( $k ) );
		Cache::set_negative( $k );
		$this->assertTrue( Cache::is_negative( $k ) );
		$this->assertEqualsWithDelta( time() + 600, (int) get_option( '_transient_timeout_' . $k . '_neg' ), 5 );
	}

	public function test_track_and_flush_post() {
		$post = self::factory()->post->create();
		$other = self::factory()->post->create();
		Cache::set( 'gistful_h_1', 'a' );
		Cache::set( 'gistful_h_2', 'b' );
		Cache::set_negative( 'gistful_h_1' );
		Cache::track( $post, 'gistful_h_1' );
		Cache::track( $post, 'gistful_h_1', 'gistful_h_2' );
		$this->assertSame( array( 'gistful_h_1', 'gistful_h_2' ), get_post_meta( $post, '_gistful_keys', true ) );
		Cache::set( 'gistful_h_other', 'c' );
		Cache::track( $other, 'gistful_h_other' );
		Cache::flush_post( $post );
		$this->assertFalse( Cache::get( 'gistful_h_1' ) );
		$this->assertFalse( Cache::get( 'gistful_h_2' ) );
		$this->assertFalse( Cache::is_negative( 'gistful_h_1' ) );
		$this->assertSame( 'c', Cache::get( 'gistful_h_other' ) );
		$this->assertEmpty( get_post_meta( $post, '_gistful_keys', true ) );
	}

	public function test_flush_all_only_touches_gistful_transients() {
		Cache::set( 'gistful_h_1', 'a' );
		set_transient( 'other_plugin', 'keep', 100 );
		Cache::flush_all();
		$this->assertFalse( Cache::get( 'gistful_h_1' ) );
		$this->assertSame( 'keep', get_transient( 'other_plugin' ) );
	}

	private function payload(): Gist_Payload {
		return new Gist_Payload( '<div class="gist"><div class="gist-file">x</div></div>', array( 'a.sh' ), 'json', array( 'a.sh' => 'echo' ), false );
	}

	public function test_backup_roundtrip_is_plain_array_and_source_backup() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc', '', $this->payload() );
		$stored = get_post_meta( $post, Backup::meta_key( 'abc', '' ), true );
		$this->assertIsArray( $stored );
		$p = Backup::get( $post, 'abc', '' );
		$this->assertSame( 'backup', $p->source );
		$this->assertSame( $this->payload()->html, $p->html );
		$this->assertSame( array( 'a.sh' ), $p->files );
		$this->assertSame( array( 'a.sh' => 'echo' ), $p->raw_files );
	}

	public function test_backup_preserves_backslashes() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc', '', new Gist_Payload( '<div class="gist">a\\nb \\\\ c</div>' ) );
		$this->assertSame( '<div class="gist">a\\nb \\\\ c</div>', Backup::get( $post, 'abc', '' )->html );
	}

	public function test_backup_written_only_when_changed() {
		$post = self::factory()->post->create();
		Backup::maybe_store( $post, 'abc', '', $this->payload() );
		$writes = 0;
		add_action(
			'update_post_meta',
			static function () use ( &$writes ) {
				++$writes;
			}
		);
		Backup::maybe_store( $post, 'abc', '', $this->payload() );
		$this->assertSame( 0, $writes );
		Backup::maybe_store( $post, 'abc', '', new Gist_Payload( '<div>new</div>' ) );
		$this->assertSame( 1, $writes );
	}

	public function test_malformed_backup_is_missing() {
		$post = self::factory()->post->create();
		update_post_meta( $post, Backup::meta_key( 'abc', '' ), array( 'html' => array( 1 ) ) );
		$this->assertNull( Backup::get( $post, 'abc', '' ) );
		update_post_meta( $post, Backup::meta_key( 'abc', '' ), 'O:8:"stdClass":0:{}' );
		$this->assertNull( Backup::get( $post, 'abc', '' ) );
	}

	public function test_legacy_meta_with_and_without_file() {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_gist_raw_' . md5( 'https://gist.github.com/abc.json' ), addslashes( '<div>plain</div>' ) );
		update_post_meta( $post, '_gist_raw_' . md5( 'https://gist.github.com/abc.json?file=a.sh' ), addslashes( '<div>filed</div>' ) );
		$this->assertSame( '<div>plain</div>', Backup::get( $post, 'abc', '' )->html );
		$p = Backup::get( $post, 'abc', 'a.sh' );
		$this->assertSame( '<div>filed</div>', $p->html );
		$this->assertSame( 'backup', $p->source );
		$this->assertNull( Backup::get( $post, 'zzz', '' ) );
	}
}
