<?php
/**
 * SystemStatusToolsAbilitiesTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Abilities\Domain;

use Automattic\WooCommerce\Internal\Abilities\AbilitiesLoader;
use Automattic\WooCommerce\Internal\Abilities\Domain\SystemStatusToolRun;

/**
 * Tests for the system-status-tools-query and system-status-tool-run abilities.
 */
class SystemStatusToolsAbilitiesTest extends \WC_Unit_Test_Case {

	/**
	 * Administrator used as the current user.
	 *
	 * @var int
	 */
	private static $administrator_id;

	/**
	 * Init action count restored in tearDown.
	 *
	 * @var int|null
	 */
	private $original_init_count;

	/**
	 * Create the administrator once for the class.
	 *
	 * @param \WP_UnitTest_Factory $factory WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$administrator_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Start each test from an empty abilities registry, so the abilities register again.
	 */
	public function setUp(): void {
		global $wp_actions;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			require_once WC_ABSPATH . 'vendor/wordpress/abilities-api/includes/bootstrap.php';
		}

		// The Abilities API registry refuses to initialize before `init` has fired.
		$this->original_init_count = $wp_actions['init'] ?? null;
		$wp_actions['init']        = max( 1, (int) ( $wp_actions['init'] ?? 0 ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		parent::setUp();

		wc_get_container()->get( AbilitiesLoader::class )->init();
		self::reset_registries();
		wp_set_current_user( self::$administrator_id );
	}

	/**
	 * Leave the registries and the init count as the next test expects them.
	 */
	public function tearDown(): void {
		global $wp_actions;

		try {
			self::reset_registries();
		} finally {
			parent::tearDown();
		}

		if ( null !== $this->original_init_count ) {
			$wp_actions['init'] = $this->original_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} else {
			unset( $wp_actions['init'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * @testdox Should list every tool in screen order and mark only the allowlisted ones runnable.
	 */
	public function test_tools_are_listed_with_runnable_flag(): void {
		$result = $this->run_ability( 'woocommerce/system-status-tools-query' );

		$this->assertNotWPError( $result );
		$expected_ids = array_map( 'strval', array_keys( ( new \WC_REST_System_Status_Tools_Controller() )->get_tools() ) );
		$this->assertSame( $expected_ids, array_column( $result['tools'], 'id' ) );

		$runnable = array_column( $result['tools'], 'runnable', 'id' );
		$this->assertTrue( $runnable['recount_terms'] );
		$this->assertFalse( $runnable['delete_taxes'], 'A tool that deletes store data should not be runnable.' );
		$this->assertSame( array( 'id', 'name', 'description', 'runnable' ), array_keys( $result['tools'][0] ) );
	}

	/**
	 * @testdox Should strip the markup from tool descriptions.
	 */
	public function test_tool_descriptions_are_plain_text(): void {
		$result = $this->run_ability( 'woocommerce/system-status-tools-query', array( 'include' => array( 'delete_taxes' ) ) );

		$this->assertCount( 1, $result['tools'] );
		$this->assertStringNotContainsString( '<', $result['tools'][0]['description'] );
		$this->assertStringContainsString( 'tax rates', $result['tools'][0]['description'] );
	}

	/**
	 * @testdox Should list an extension tool as not runnable, without its callback.
	 */
	public function test_extension_tool_is_listed_as_not_runnable(): void {
		$this->add_extension_tool( static fn() => 'ran' );

		$result = $this->run_ability( 'woocommerce/system-status-tools-query', array( 'include' => array( 'extension_tool' ) ) );

		$this->assertSame(
			array(
				'id'          => 'extension_tool',
				'name'        => 'Extension tool',
				'description' => 'Added by an extension.',
				'runnable'    => false,
			),
			$result['tools'][0]
		);
	}

	/**
	 * @testdox Should run every allowlisted tool.
	 */
	public function test_every_allowlisted_tool_runs(): void {
		foreach ( SystemStatusToolRun::RUNNABLE_TOOLS as $id ) {
			$result = $this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => $id ) );

			$this->assertNotWPError( $result, "{$id} should run." );
			$this->assertSame( $id, $result['id'] );
			$this->assertNotSame( '', $result['message'], "{$id} should report what it did." );
		}
	}

	/**
	 * @testdox Should fire the tool executed action with the tool and its result.
	 */
	public function test_run_fires_tool_executed_action(): void {
		$executed = array();
		add_action(
			'woocommerce_system_status_tool_executed',
			static function ( $tool ) use ( &$executed ): void {
				$executed[] = $tool;
			}
		);

		$this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => 'recount_terms' ) );

		$this->assertCount( 1, $executed );
		$this->assertSame( 'recount_terms', $executed[0]['id'] );
		$this->assertTrue( $executed[0]['success'] );
	}

	/**
	 * @testdox Should refuse a destructive tool and leave the store data in place.
	 */
	public function test_destructive_tool_is_refused(): void {
		$rate_id = \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country' => 'PT',
				'tax_rate'         => '23.0000',
				'tax_rate_name'    => 'IVA',
			)
		);

		$result = $this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => 'delete_taxes' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertNotEmpty( \WC_Tax::_get_tax_rate( $rate_id ), 'The tax rate should still exist.' );
	}

	/**
	 * @testdox Should refuse an extension tool without calling its callback.
	 */
	public function test_extension_tool_is_refused(): void {
		$called = false;
		$this->add_extension_tool(
			static function () use ( &$called ): string {
				$called = true;
				return 'ran';
			}
		);

		$result = $this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => 'extension_tool' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertFalse( $called, 'The extension callback should not run.' );
	}

	/**
	 * @testdox Should refuse an allowlisted tool that a filter removed from the Tools screen.
	 */
	public function test_tool_removed_by_filter_is_not_found(): void {
		add_filter(
			'woocommerce_debug_tools',
			static function ( array $tools ): array {
				unset( $tools['recount_terms'] );
				return $tools;
			}
		);

		$result = $this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => 'recount_terms' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_ability_system_status_tool_not_found', $result->get_error_code() );
	}

	/**
	 * @testdox Should refuse to list or run tools for a user who cannot manage WooCommerce.
	 */
	public function test_customer_cannot_list_or_run_tools(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$query = $this->run_ability( 'woocommerce/system-status-tools-query' );
		$run   = $this->run_ability( 'woocommerce/system-status-tool-run', array( 'id' => 'recount_terms' ) );

		$this->assertSame( 'ability_invalid_permissions', $query->get_error_code() );
		$this->assertSame( 'ability_invalid_permissions', $run->get_error_code() );
	}

	/**
	 * Add a tool through the filter extensions use.
	 *
	 * @param callable $callback Tool callback.
	 */
	private function add_extension_tool( callable $callback ): void {
		add_filter(
			'woocommerce_debug_tools',
			static function ( array $tools ) use ( $callback ): array {
				$tools['extension_tool'] = array(
					'name'     => 'Extension tool',
					'button'   => 'Run',
					'desc'     => '<em>Added by an extension.</em>',
					'callback' => $callback,
				);
				return $tools;
			}
		);
	}

	/**
	 * Run an ability as the current user, with its permission check.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Ability input.
	 * @return mixed|\WP_Error
	 */
	private function run_ability( string $name, array $input = array() ) {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "Ability {$name} should be registered." );

		return $ability->execute( $input );
	}

	/**
	 * Drop every registered ability, so the next read registers them again.
	 */
	private static function reset_registries(): void {
		global $wp_actions;

		foreach ( array( 'WP_Abilities_Registry', 'WP_Ability_Categories_Registry' ) as $registry ) {
			if ( class_exists( $registry ) ) {
				$instance = new \ReflectionProperty( $registry, 'instance' );
				$instance->setAccessible( true );
				$instance->setValue( null, null );
			}
		}
		unset( $wp_actions['wp_abilities_api_init'], $wp_actions['wp_abilities_api_categories_init'] );
	}
}
