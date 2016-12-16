<?php

/**
 * This class tries to preserve most of the interface to the original Database class
 * while getting rid of some of the older unused stuff, and introducing query placeholders.
 *
 * There are a couple of non-obvious pieces of functionality:
 *
 * - Functions with $data parameters (i.e. insert, and update) allow raw SQL to be inserted as values
 *   if the key is prefixed with 'xx_'. For example:
 *
 *	     insert( 'table', array( 'user' => 'sean o\'leary', 'xx_timestamp' => 'CURRENT_TIMESTAMP' ) );
 *
 *	  Will be translated to:
 *
 *	     INSERT INTO `table` (`user`, `timestamp`) VALUES ('sean o''leary', CURRENT_TIMESTAMP);
 *
 * - Functions accepting free form queries or where clauses can handle named placeholders
 *   (not ? ones, those are used internally) in the $args parameter. For example:
 *
 *      selectValue( 'SELECT id FROM users WHERE username = :username AND type IN (:type)', array( 'username' => 'bob\'smith', 'type' => array( 1, 2 ) ) );
 *
 *   Will be translated to:
 *
 *      SELECT id FROM users WHERE username = 'bob''smith' AND type IN (1, 2)
 *
 * @throws DatabaseException All methods throw this exception on PDO errors.
 */
class Database2 {
	/**
	 * Used to keep a count of how many transact blocks we have nested. Having a single counter per instance
	 * should be safe because PHP is synchronous and single threaded.
	 */
	private $transaction_counter = 0;

	/**
	 * Contains a log of all queries and parameters executed in this request. Used for debugging.
	 */
	public $queries = array();

	public function __construct( PDO $pdo = null ) {

		if ( $pdo === null ) {

			require('connect.php');

			try {
				$this->pdo = new PDO(
					'mysql:host=' . $this->db2_host . ';port=' . $this->db2_port . ';dbname=' . $this->db_name . ';charset=utf8',
					$this->db_user,
					$this->db_pass
				);
				// This only needed for PHP < 5.4
				$this->pdo->exec("SET NAMES 'utf8'");
			} catch ( PDOException $e ) {
				throw new DatabaseException( $e );
			}
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		} else {
			// This is passed in non-null for testing purposes
			$this->pdo = $pdo;
		}
	}

	/******************************************
	 ********** CSV column functions **********
	 ******************************************/

	/**
	 * Appends $value to the given $column for all rows matching $where/$args in $table.
	 * If the value already exists in the column no append will happen (nothing will
	 * be added or reordered).
	 */
	public function addCsv( $value, $table, $column, $where, $args = null ) {
		$func = function( array $col ) use ( $value ) {
			$existing = array_search( $value, $col, true ) !== false;

			return $existing ? $col : array_merge( $col, array( $value ) );
		};
		$this->updateCsv( $func, $table, $column, $where, $args );
	}

	/**
	 * Removes $value from the given $column for all rows matching $where/$args in $table.
	 */
	public function removeCsv( $value, $table, $column, $where, $args = null ) {
		$func = function( array $col ) use ( $value ) {
			$filter = function( $item ) use ( $value ) {
				return $item !== (string) $value;
			};
			return array_values( array_filter( $col, $filter ) );
		};
		$this->updateCsv( $func, $table, $column, $where, $args );
	}

	/**
	 * Updates a column containing CSV values (e.g. album_photos) using $func.
	 * Public method for PHP 5.3.3 $that compatability (should be private)
	 *
	 * @param callable(array $val) $func Called to modify the value of $column for each row.
	 * @param array $column The name of the column to modify.
	 * @param string The name of the primary key of the table.
	 * @return void
	 */
	public function updateCsv( $func, $table, $column, $where, $args = null ) {
		$primary_key = $this->getPrimaryKey( $table );

		$that = $this;
		$this->transact( function( $db ) use ( $that, $func, $table, $column, $primary_key, $where, $args ) {
			$cols = implode( ',', array( $column, $primary_key ) );
			$rows = $db->selectAll( "SELECT $cols FROM $table WHERE $where LOCK IN SHARE MODE", $args );
			foreach( $rows as $row ) {
				$val = $row[$column];
				$arr = empty( $val ) ? array() : $that->decodeCsv( $val );
				$new_arr = $func( $arr );
				$new_val = $that->encodeCsv( $new_arr );
				if ( $new_val === '' ) $new_val = null;

				$db->update( $table, array(
					$column => $new_val
				), "$primary_key = :primary_key", array(
					'primary_key' => $row[$primary_key]
				) );
			}
		} );
	}

	private function getPrimaryKey( $table_name ) {
		$ends_with = function( $string, $fragment ) {
			$pos = strrpos( $string, $fragment );
			return $pos === strlen( $string ) - strlen( $fragment );
		};
		if ( $ends_with( $table_name, 'ies' ) ) {
			return substr( $table_name, 0, strlen( $table_name ) - 3 ) . 'y_id';
		} else if ( $ends_with( $table_name, 's' ) ) {
			return substr( $table_name, 0, strlen( $table_name ) - 1 ) . '_id';
		} else {
			return $table_name . '_id';
		}
	}

	/**
	 * Encodes the values of the given array as a CSV string, suitable for decoding with decode_csv.
	 * @return mixed If count($items) === 0 null else the encoded string
	 */
	public function encodeCsv( array $items ) {
		$cleaned_items = array_values( array_filter( $items, array( $this, 'isEmpty' ) ) );
		if ( count( $cleaned_items ) === 0 ) return null;

		$output = array();
		foreach( $cleaned_items as $item ) {
			$escaped_slashes = str_replace( '\\', '\\\\', $item );
			$escaped_commas = str_replace( ',', '\\,', $escaped_slashes );
			$output[] = $escaped_commas;
		}
		return implode(',', $output);
	}

	/**
	 * Converts a string encoded using encode_csv to an array.
	 * @return array The decoded array (of strings), returns an empty array if is_null($csv_string)
	 */
	public function decodeCsv( $csv_string ) {
		if ( $csv_string === null ) return array();
		$rtn = array();
		$buffer = '';
		$mode = 'normal';
		for( $i=0; $i<strlen( $csv_string ); $i++ ) {
			$c = substr( $csv_string, $i, 1 );
			switch ( $c ) {
			case '\\':
				if ( $mode === 'normal' ) {
					$mode = 'escaping';
				} else {
					$buffer .= $c;
					$mode = 'normal';
				}
				break;
			case ',':
				if ( $mode === 'normal' ) {
					$rtn[] = $buffer;
					$buffer = '';
				} else  {
					$buffer .= $c;
					$mode = 'normal';
				}
				break;
			default:
				$buffer .= $c;
			}
		}
		$rtn[] = $buffer;

		$cleaned_items = array_values( array_filter( $rtn, array( $this, 'isEmpty' ) ) );
		return $cleaned_items;
	}

	private function isEmpty( $value ) {
		return !is_null( $value ) && $value !== '';
	}

	/**
	 * Temporarily copied from the original Database class, used by gateway.php and
	 * the newer services. Should be removed when a generic Log class is
	 * implemented as part of tasks#42.
	 */
	function error( $context, \Exception $e, $description = null )
	{
		$logged_message = ($description === null) ? '' : $description . "\n";
		$logged_message .= $e->__toString();

		$a = array(
			'error_description' => $logged_message,
			'error_context' => $context,
			'error_server' => @$_ENV["COMPUTERNAME"],
			'error_agent' => @$_SERVER['HTTP_USER_AGENT']
		);

		$this->insert('errors', $a);

	}


	/******************************************
	 ******** Standard CRUD functions *********
	 ******************************************/

	/**
	 * Runs the given function in a transaction, rolling back if it throws any exceptions. Allows nested
	 * transactions.
	 *
	 * @param callable A function accepting one argument (a reference to this Database2 class).
	 * @return The return value of the callable.
	 */
	public function transact( $func ) {

		try {

			$this->transaction_counter++;
			if ( $this->transaction_counter === 1 ) {
				$this->pdo->beginTransaction();
			}
			try {
				$result = $func( $this );
				$this->transaction_counter--;
				if ( $this->transaction_counter === 0 ) {
					$this->pdo->commit();
				}
				return $result;

			} catch ( Exception $e ) {
				$this->pdo->rollBack();
				$this->transaction_counter = 0;
				throw $e;
			}

		} catch ( PDOException $e ) {
			throw new DatabaseException( $e );
		}

	}

	/**
	 * @return The first column of the first row of the result set, or false if there's no result.
	 */
	public function selectValue( $query, array $args = null ) {
		try {
			$result = $this->query( $query, $args );
			return $result->fetchColumn();
			return empty( $col ) ? false : $col;
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e );
		}
	}

	/**
	 * @return \Iterator An iterator returning associative arrays of results.
	 */
	public function selectAll( $query, array $args = null ) {
		try {
			$result = $this->query( $query, $args );
			return new \ArrayIterator( $result->fetchAll( \PDO::FETCH_ASSOC ) );
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e );
		}
	}

	/**
	 *	@return The id of the last inserted row, or true if the table doesn't have a primary key.
	 *		Returns '0' if the table doesn't have an primary autoincrement column.
	 */
	public function insert( $table, array $data ) {
		try {
			list( $fields, $values, $args ) = $this->processDataArray( $data );
			$fields_imploded = implode( ',', $fields );
			$values_imploded = implode( ',', $values );
			$table_escaped = $this->escape_field( $table );
			$this->query( "INSERT INTO $table_escaped ($fields_imploded) VALUES ($values_imploded)", $args );

			return $this->pdo->lastInsertId();
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e );
		}
	}

	/**
	 * @return number of rows updated.
	 */
	public function update( $table, array $data, $where, array $args = null ) {
		try {
			list( $fields, $values, $data_args ) = $this->processDataArray( $data );
			$set_bits = array_map( function( $key, $val ) {
				return $key . '=' . $val;
			}, $fields, $values );
			$set = implode( ',', $set_bits );
			$merged_args = array_merge( $data_args, $args === null ? array() : $args );
			$table_escaped = $this->escape_field( $table );
			$statement = $this->query( "UPDATE $table_escaped SET $set WHERE $where", $merged_args );
			return $statement->rowCount();
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e );
		}
	}



	/**
	 * @return void
	 */
	public function delete( $table, $where, array $args = null ) {
		try {
			$table_escaped = $this->escape_field( $table );
			$this->query( "DELETE FROM $table_escaped WHERE $where", $args );
		} catch ( \PDOException $e ) {
			throw new DatabaseException( $e );
		}
	}



	private function query( $query, array $args = null ) {

		list( $expanded_query, $final_args ) = $this->handleArrays( $query, $args );

		$statement = $this->pdo->prepare( $expanded_query );
		$statement->execute( $final_args );

		// Log queries
		$this->queries[] = array(
			'query' => $expanded_query,
			'values' => $final_args
		);

		return $statement;

	}



	/**
	 * Expands keywords in the query corresponding to arrays in the args list. For example:
	 *
	 *		"SELECT * FROM t WHERE f IN :stuff", array("stuff"=>array(1,2,3))
	 *	Would expand to:
	 *
	 *
	 *		"SELECT * FROM t WHERE f IN (:stuff_0, :stuff_1, :stuff_2)", array("stuff_0"=>1,"stuff_1"=>2,"stuff_2"=>3)
	 */
	private function handleArrays( $query, $args ) {
		$args = $args === null ? array() : $args;
		$final_args = array();
		$expanded_query = $query;
		foreach( $args as $key=>$value ) {
			if ( is_array( $value ) ) {
				$non_empty_array = count( $value ) === 0 ? array( '' ) : $value;
				$key_names = array();
				foreach( $non_empty_array as $i=>$item ) {
					$name = ':' . $key . '_' . $i;
					$key_names[] = $name;
					$final_args[$name] = $item;
				}
				$query_frag = implode( ',', $key_names );
				$expanded_query = preg_replace( '/(?<=\W):' . $key . '(?=(\W|\z))/', '('.$query_frag.')', $expanded_query );
			} else {
				$final_args[$key] = $value;
			}
		}

		return array( $expanded_query, $final_args );
	}

	private function processDataArray( array $data ) {
		$fields = array();
		$values = array();
		$args = array();
		foreach( $data as $field => $value ) {
			if ( strpos( $field, 'xx_' ) === 0 ) {
				$field_stripped = substr( $field, 3 );
				$fields[] = $this->escape_field( $field_stripped );
				$values[] = $value;
			} else {
				$fields[] = $this->escape_field( $field );
				$escaped = $this->escape_placeholder( $field );
				$values[] = ":${escaped}_value";
				$args["${escaped}_value"] = $value === '' ? null : $value;
			}
		}

		return array( $fields, $values, $args );
	}


	private function escape_field( $fieldname ) {
		return '`' . str_replace( '`', '``', $fieldname ) . '`';
	}

	private function escape_placeholder( $placeholder ) {
		return str_replace( '`', 'tick', $placeholder );
	}
}

// Allow for DB specific catches
class DatabaseException extends \RuntimeException {}
