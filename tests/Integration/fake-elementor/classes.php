<?php
/**
 * Minimal stand-ins with the same names and methods the plugin uses (Elementor 3.x API).
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test fixture, not shipped.

namespace Elementor {
	class Controls_Manager {
		const NUMBER = 'number';
		const SELECT = 'select';
		const TEXT   = 'text';
	}

	abstract class Widget_Base {
		public array $dgb_controls = array();
		private array $dgb_settings;
		public function __construct( array $data = array(), $args = null ) {
			$this->dgb_settings = $data['settings'] ?? array();
		}
		public function start_controls_section( $id, array $args = array() ) {}
		public function end_controls_section() {}
		public function add_control( $id, array $args = array() ) {
			$this->dgb_controls[ $id ] = $args;
		}
		public function get_settings_for_display( $key = null ) {
			return null === $key ? $this->dgb_settings : ( $this->dgb_settings[ $key ] ?? null );
		}
		public function dgb_output(): string {
			$this->register_controls();
			ob_start();
			$this->render();
			return (string) ob_get_clean();
		}
		abstract protected function register_controls();
		abstract protected function render();
	}
}

namespace Elementor\Core\DynamicTags {
	abstract class Base_Tag {
		public array $dgb_controls = array();
		private array $dgb_settings;
		public function __construct( array $data = array() ) {
			$this->dgb_settings = $data['settings'] ?? array();
		}
		public function add_control( $id, array $args = array() ) {
			$this->dgb_controls[ $id ] = $args;
		}
		public function get_settings( $key = null ) {
			return null === $key ? $this->dgb_settings : ( $this->dgb_settings[ $key ] ?? null );
		}
		abstract public function get_name();
		abstract public function get_title();
		abstract public function get_group();
		abstract public function get_categories();
	}

	abstract class Tag extends Base_Tag {
		abstract public function render();
		public function dgb_output(): string {
			$this->register_controls();
			ob_start();
			$this->render();
			return (string) ob_get_clean();
		}
	}

	abstract class Data_Tag extends Base_Tag {
		abstract public function get_value( array $options = array() );
		public function dgb_output(): string {
			$this->register_controls();
			return (string) $this->get_value();
		}
	}
}

namespace Elementor\Modules\DynamicTags {
	class Module {
		const TEXT_CATEGORY = 'text';
		const URL_CATEGORY  = 'url';
	}
}
