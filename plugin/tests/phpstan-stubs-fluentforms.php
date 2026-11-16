<?php
/**
 * The few classes of form plugins the exit's form adapters call (Export\Core\Forms), for PHPStan only.
 */

namespace FluentForm\App\Models {
	class Query {
		/** @return int|float|string|null */
		public function max( string $column ) {}
	}
	class Form {
		/** @param array<string, mixed> $data */
		public static function insertGetId( array $data ): int {}
	}
	class FormMeta {
		/** @param array<string, mixed> $data */
		public static function insert( array $data ): bool {}
	}
	class Submission {
		/** @param array<string, mixed> $data */
		public static function insertGetId( array $data ): int {}
		public static function where( string $column, mixed $value ): Query {}
	}
}

namespace FluentForm\App\Services\Submission {
	class SubmissionService {
		/** @param array<string, mixed> $data */
		public function recordEntryDetails( int $entryId, int $formId, array $data ): void {}
	}
}

namespace {
	/** @return mixed */
	function fluentformLoadFile( string $path ) {}
}

namespace {
	class WPCF7_ContactForm {
		/** @param array<string, mixed>|string $options */
		public static function get_template( $options = '' ): self {}
		public static function get_instance( mixed $post ): ?self {}
		/** @return mixed */
		public function prop( string $name ) {}
		/** @param array<string, mixed> $properties */
		public function set_properties( array $properties ): void {}
		/** @return int|false */
		public function save() {}
		public function hash( int $length = 7 ): string {}
		public function title(): string {}
	}
}

namespace {
	class Forminator_API {
		/**
		 * @param array<mixed> $wrappers
		 * @param array<mixed> $settings
		 * @return int|\WP_Error
		 */
		public static function add_form( string $name, array $wrappers = array(), array $settings = array(), string $status = 'publish' ) {}
		/**
		 * @param array<mixed> $entry_meta
		 * @return int|\WP_Error
		 */
		public static function add_form_entry( int $form_id, array $entry_meta ) {}
	}
}

namespace JFB_Modules\Form_Record\Models {
	class Record_Model {
		/** @param array<string, mixed> $columns */
		public function insert( array $columns ): int {}
	}
	class Record_Field_Model {
		/** @param array<string, mixed> $columns */
		public function insert( array $columns ): int {}
	}
}

namespace {
	/** @return object */
	function jet_form_builder() {}
}

namespace {
	class NF_Model {
		/** @param array<string, mixed> $settings */
		public function update_settings( array $settings ): self {}
		public function update_setting( string $key, mixed $value ): self {}
		public function update_field_value( string $ref, mixed $value ): self {}
		public function save(): self {}
		/** @return int|string */
		public function get_id() {}
	}
	class NF_Factory {
		public function get(): NF_Model {}
		public function field( mixed $id = '' ): self {}
		public function action( mixed $id = '' ): self {}
		public function sub( mixed $id = '' ): self {}
	}
	class NF_Main {
		public function form( mixed $id = '' ): NF_Factory {}
	}
	function Ninja_Forms(): NF_Main {}
	class WPN_Helper {
		public static function build_nf_cache( int $id ): void {}
	}
}

namespace SRFM\Inc\Database\Tables {
	class Entries {
		/**
		 * @param array<string, mixed> $data
		 * @return int|false
		 */
		public static function add( array $data ) {}
	}
}

namespace SRFM\Inc {
	class Create_New_Form {
		/** @return array<string, mixed> */
		public static function get_default_meta_keys(): array {}
	}
}
