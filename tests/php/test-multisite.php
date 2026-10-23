<?php
/**
 * Network activation, new sites, network deactivation and per-site tables.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Multisite tests.
 *
 * @group ms-required
 * @group multisite
 */
class Test_FQJ_Multisite extends FQJ_Multisite_TestCase {

	public function test_for_each_site_switches_to_every_site_and_restores() {
		$site_ids = self::factory()->blog->create_many( 2 );
		$seen     = array();

		fqj_for_each_site(
			function () use ( &$seen ) {
				global $wpdb;
				$seen[ get_current_blog_id() ] = fqj_table_name() === $wpdb->prefix . 'fqj_mappings';
			}
		);

		$expected = array_fill_keys( array_merge( array( get_main_site_id() ), $site_ids ), true );
		ksort( $seen );
		ksort( $expected );
		$this->assertSame( $expected, $seen );
		$this->assertSame( get_main_site_id(), get_current_blog_id() );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_network_activation_sets_up_every_site() {
		$site_ids = self::factory()->blog->create_many( 2 );
		foreach ( $site_ids as $site_id ) {
			foreach ( $this->plugin_tables( $site_id ) as $table ) {
				$this->assertFalse( $this->table_exists( $table ), "{$table} should not exist before activation" );
			}
			$this->assertFalse( get_blog_option( $site_id, FQJ_OPTION_KEY ) );
		}

		fqj_activate( true );

		foreach ( array_merge( array( get_main_site_id() ), $site_ids ) as $site_id ) {
			foreach ( $this->plugin_tables( $site_id ) as $table ) {
				$this->assertTrue( $this->table_exists( $table ), "{$table} should exist after activation" );
			}

			switch_to_blog( $site_id );
			$opts       = get_option( FQJ_OPTION_KEY );
			$db_version = get_option( FQJ_DB_VERSION_OPTION );
			$data       = get_option( FQJ_DATA_VERSION_OPTION );
			$next       = wp_next_scheduled( 'fqj_process_invalidation_queue' );
			$schedule   = wp_get_schedule( 'fqj_process_invalidation_queue' );
			restore_current_blog();

			$this->assertSame( 12 * HOUR_IN_SECONDS, $opts['cache_ttl'], "site {$site_id}" );
			$this->assertSame( 500, $opts['batch_size'], "site {$site_id}" );
			$this->assertSame( 'faqpage', $opts['output_type'], "site {$site_id}" );
			$this->assertSame( FQJ_DB_VERSION, $db_version, "site {$site_id}" );
			$this->assertSame( FQJ_DATA_VERSION, $data, "site {$site_id}" );
			$this->assertNotFalse( $next, "site {$site_id}" );
			$this->assertSame( 'fqj_five_minutes', $schedule, "site {$site_id}" );
		}
		$this->assertSame( get_main_site_id(), get_current_blog_id() );
	}

	public function test_site_activation_only_sets_up_current_site() {
		$site_id = self::factory()->blog->create();

		fqj_activate( false );

		$this->assertNotFalse( get_option( FQJ_OPTION_KEY ) );
		$this->assertFalse( get_blog_option( $site_id, FQJ_OPTION_KEY ) );
		foreach ( $this->plugin_tables( $site_id ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ) );
		}
	}

	public function test_network_activation_keeps_each_sites_settings() {
		$site_id = self::factory()->blog->create();
		update_blog_option(
			$site_id,
			FQJ_OPTION_KEY,
			array(
				'cache_ttl'   => 120,
				'output_type' => 'FAQSection',
			)
		);

		fqj_activate( true );

		$opts = get_blog_option( $site_id, FQJ_OPTION_KEY );
		$this->assertSame( 120, $opts['cache_ttl'] );
		$this->assertSame( 500, $opts['batch_size'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
		$this->assertSame( 12 * HOUR_IN_SECONDS, get_option( FQJ_OPTION_KEY )['cache_ttl'] );
	}

	public function test_network_activation_renames_legacy_faqs_on_every_site() {
		$site_id = self::factory()->blog->create();
		$faqs    = array();
		foreach ( array( get_main_site_id(), $site_id ) as $id ) {
			switch_to_blog( $id );
			$faqs[ $id ] = self::factory()->post->create( array( 'post_type' => 'faq_item' ) );
			delete_option( FQJ_POST_TYPE_OPTION );
			restore_current_blog();
		}

		fqj_activate( true );

		foreach ( $faqs as $id => $faq ) {
			switch_to_blog( $id );
			$type = get_post( $faq )->post_type;
			restore_current_blog();
			$this->assertSame( FQJ_POST_TYPE, $type, "site {$id}" );
		}
	}

	public function test_new_site_is_set_up_when_network_active() {
		$this->network_activate_plugin();

		$site_id = self::factory()->blog->create();

		foreach ( $this->plugin_tables( $site_id ) as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "{$table} should exist" );
		}
		switch_to_blog( $site_id );
		$opts       = get_option( FQJ_OPTION_KEY );
		$db_version = get_option( FQJ_DB_VERSION_OPTION );
		$next       = wp_next_scheduled( 'fqj_process_invalidation_queue' );
		restore_current_blog();

		$this->assertSame( 500, $opts['batch_size'] );
		$this->assertSame( FQJ_DB_VERSION, $db_version );
		$this->assertNotFalse( $next );
		$this->assertSame( get_main_site_id(), get_current_blog_id() );
	}

	public function test_new_site_is_left_alone_when_not_network_active() {
		$this->assertFalse( is_plugin_active_for_network( plugin_basename( FQJ_PLUGIN_DIR . 'faq-jsonld.php' ) ) );

		$site_id = self::factory()->blog->create();

		foreach ( $this->plugin_tables( $site_id ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "{$table} should not exist" );
		}
		$this->assertFalse( get_blog_option( $site_id, FQJ_OPTION_KEY ) );
		$this->assertFalse( get_blog_option( $site_id, FQJ_DB_VERSION_OPTION ) );
		switch_to_blog( $site_id );
		$next = wp_next_scheduled( 'fqj_process_invalidation_queue' );
		restore_current_blog();
		$this->assertFalse( $next );
	}

	public function test_network_deactivation_clears_cron_on_every_site() {
		$site_ids = self::factory()->blog->create_many( 2 );
		$all      = array_merge( array( get_main_site_id() ), $site_ids );
		foreach ( $all as $site_id ) {
			switch_to_blog( $site_id );
			wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
			wp_schedule_single_event( time() + 10, 'fqj_data_migration' );
			wp_schedule_single_event( time() + 10, 'unrelated_event' );
			restore_current_blog();
		}

		fqj_deactivate( true );

		foreach ( $all as $site_id ) {
			switch_to_blog( $site_id );
			$queue     = wp_next_scheduled( 'fqj_process_invalidation_queue' );
			$migration = wp_next_scheduled( 'fqj_data_migration' );
			$unrelated = wp_next_scheduled( 'unrelated_event' );
			restore_current_blog();

			$this->assertFalse( $queue, "site {$site_id}" );
			$this->assertFalse( $migration, "site {$site_id}" );
			$this->assertNotFalse( $unrelated, "site {$site_id}" );
		}
	}

	public function test_site_deactivation_only_clears_current_site() {
		$site_id = self::factory()->blog->create();
		switch_to_blog( $site_id );
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
		restore_current_blog();
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );

		fqj_deactivate( false );

		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
		switch_to_blog( $site_id );
		$next = wp_next_scheduled( 'fqj_process_invalidation_queue' );
		restore_current_blog();
		$this->assertNotFalse( $next );
	}

	public function test_table_names_follow_switched_site() {
		global $wpdb;
		$site_id = self::factory()->blog->create();
		$main    = fqj_table_name();
		$queue   = fqj_queue_table_name();

		switch_to_blog( $site_id );
		$this->assertSame( $wpdb->get_blog_prefix( $site_id ) . 'fqj_mappings', fqj_table_name() );
		$this->assertSame( $wpdb->get_blog_prefix( $site_id ) . 'fqj_queue', fqj_queue_table_name() );
		$this->assertNotSame( $main, fqj_table_name() );
		$this->assertNotSame( $queue, fqj_queue_table_name() );
		restore_current_blog();

		$this->assertSame( $main, fqj_table_name() );
		$this->assertSame( $queue, fqj_queue_table_name() );
	}

	public function test_index_and_queue_writes_stay_on_switched_site() {
		$this->network_activate_plugin();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$faq = $this->create_faq( 'Q', 'A', array( 'global' => true ) );
		fqj_queue_add_posts( array( 42 ) );
		$site_mappings = $this->get_mappings( $faq );
		$site_queue    = $this->get_queue();
		restore_current_blog();

		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'global',
					'mapping_value' => '1',
				),
			),
			$site_mappings
		);
		$this->assertSame( array( 42 ), $site_queue );
		$this->assertSame( array(), $this->get_mappings( $faq ) );
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_url_migration_runs_per_site_with_site_relative_urls() {
		$this->network_activate_plugin();
		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$this->set_permalink_structure( '/%postname%/' );
		$post      = self::factory()->post->create( array( 'post_name' => 'about' ) );
		$shortlink = home_url( '/?p=' . $post );
		$faq       = $this->create_faq( 'Q', 'A' );
		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array( 'urls' => array( $shortlink ) ) ) );
		fqj_insert_mappings(
			$faq,
			array(
				array(
					'type'  => 'url',
					'value' => $shortlink,
				),
				array(
					'type'  => 'post',
					'value' => $post,
				),
			)
		);
		update_option( FQJ_DATA_VERSION_OPTION, '2' );
		fqj_run_data_migration();

		$site_report   = get_option( FQJ_URL_MIGRATION_REPORT_OPTION );
		$site_mappings = $this->get_mappings( $faq );
		$site_matches  = fqj_faq_ids_for_post( $post );
		$main_url      = fqj_normalize_url( network_home_url( '/about/' ) );
		$permalink     = get_permalink( $post );
		restore_current_blog();

		$this->assertSame( $permalink, $site_report[0]['new'] );
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'url',
					'mapping_value' => '/about',
				),
			),
			$site_mappings
		);
		$this->assertSame( array( $faq ), $site_matches );
		$this->assertSame( untrailingslashit( network_home_url( '/about/' ) ), $main_url, 'The main site is outside this site\'s root.' );
		$this->assertFalse( get_option( FQJ_URL_MIGRATION_REPORT_OPTION ) );
	}
}
