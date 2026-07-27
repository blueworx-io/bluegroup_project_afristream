<?php
/**
 * Stand-ins for the two SureCart models the entitlement lookup queries.
 *
 * Without these the harness has no SureCart at all, so
 * afristream_subscription_count() returned at its class_exists() guard and its
 * whole body — the customer lookup, the ID extraction, the status re-check, the
 * error paths — never ran under test. That body decides how many licences a
 * paying customer is owed, which makes it the last thing that should be
 * uncovered.
 *
 * Kept no more permissive than the real models on the point that matters: a
 * SureCart model carries its attributes in a private bag and exposes them
 * through __get and ArrayAccess, never as public properties, which is the whole
 * reason afristream_affiliate_prop() exists. Public properties here would let a
 * caller reach an attribute in a way the live site does not support and pass
 * anyway. __isset() is deliberately not implemented either, so `?? ` behaves as
 * unreliably as it does against the real thing.
 *
 * Separate from bootstrap.php only because PHP forbids a namespace declaration
 * in a file that has code outside one, and the alternative was reindenting the
 * entire bootstrap into a braced global namespace.
 */

namespace {

	/**
	 * A SureCart model: attributes in, attributes readable only through the
	 * accessors the live models offer.
	 */
	class AF_Fake_SureCart_Model implements ArrayAccess {

		/** @var array<string,mixed> Private, exactly as the real models keep it. */
		private $attributes;

		/**
		 * @param array<string,mixed> $attributes Model attributes.
		 */
		public function __construct( array $attributes ) {
			$this->attributes = $attributes;
		}

		/**
		 * @param string $key Attribute name.
		 * @return mixed Null when the attribute is not set, like the real models.
		 */
		public function __get( $key ) {
			return array_key_exists( $key, $this->attributes ) ? $this->attributes[ $key ] : null;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->attributes[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->__get( $offset );
		}

		/**
		 * Reading is all the plugin does; a write would be a test reaching past
		 * what SureCart's API actually offers, so it is refused rather than
		 * quietly allowed.
		 */
		public function offsetSet( mixed $offset, mixed $value ): void {
			throw new RuntimeException( 'Fake SureCart model: attributes are read-only.' );
		}

		public function offsetUnset( mixed $offset ): void {
			throw new RuntimeException( 'Fake SureCart model: attributes are read-only.' );
		}
	}

	/**
	 * What ::where() hands back. SureCart returns a query builder that only runs
	 * once ->get() is called, and the plugin chains the two, so the fake keeps
	 * the same two steps rather than collapsing them.
	 */
	class AF_Fake_SureCart_Query {

		/** @var array|WP_Error Already-resolved result. */
		private $result;

		/**
		 * @param array|WP_Error $result What ->get() should answer.
		 */
		public function __construct( $result ) {
			$this->result = $result;
		}

		/**
		 * @return array|WP_Error
		 */
		public function get() {
			return $this->result;
		}
	}
}

namespace SureCart\Models {

	/** The customer record that joins a WordPress user to SureCart. */
	class Customer {

		/**
		 * @param array $args Query arguments; user_ids is the one honoured.
		 * @return \AF_Fake_SureCart_Query
		 */
		public static function where( $args = array() ) {
			return new \AF_Fake_SureCart_Query( \af_surecart_customers( $args ) );
		}
	}

	/** A subscription belonging to a customer. */
	class Subscription {

		/**
		 * @param array $args Query arguments; customer_ids is the one honoured.
		 * @return \AF_Fake_SureCart_Query
		 */
		public static function where( $args = array() ) {
			return new \AF_Fake_SureCart_Query( \af_surecart_subscriptions( $args ) );
		}
	}
}
