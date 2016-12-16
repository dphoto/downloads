<?php

class Database
{


	/* ---------------------------------------

	DATABASE

	--------------------------------------- */

	public $app_id;
	public $queries;
	public $user_id;


	function Database($app_id = '', $user_id = 0, $encoding = 'utf8' )
	{

		// Connection vars
		require('connect.php');

		// Set vars for reporting
		$this->app_id = $app_id;
		$this->user_id = $user_id;
		$this->encoding = $encoding;
		$this->queries = array();

	}




	/* ---------------------------------------

	QUERY

	--------------------------------------- */

	function query($query)
	{



		// Check for existing
		 if(!isset($this->db_connection)) {

			// Make connection to the database.
			$this->db_connection = @mysql_connect($this->db_host, $this->db_user, $this->db_pass);

			// Couldn't connect to db,
			if(!$this->db_connection) throw new Exception("Database connection error", 2);//die("Connection problems");

			mysql_set_charset( $this->encoding, $this->db_connection );

			// Select database
			$this->db_state = mysql_select_db($this->db_name, $this->db_connection);

			// Check for selected db
			if(!$this->db_state) throw new Exception("Database connection error", 2);//die("Connection problems");

		 }

		// Perform query
		$result = mysql_query($query);

		// Log error and reurn
		if(mysql_errno() > 0) $this->error('Database error', mysql_error() . ' :: ' . $query, mysql_errno());

		// Keep history for logging
		array_push($this->queries, $query);

		// Return info to the function
		return $result;

	}





	/* ---------------------------------------

	CLOSE

	--------------------------------------- */

	function close()
	{

		if(!isset($this->db_connection)) {

			mysql_close($this->db_connection);

			unset($this->db_connection, $this->db_state);

		}

	}




	/* ---------------------------------------

	SELECT

	--------------------------------------- */

	function select($query, $format = 'all')
	{

		// Execute query
		$result = $this->query($query);

		// Format the result
		if($result === false) return false;
		else if($format == 'all') return $result;
		else if($format == 'row') return mysql_fetch_assoc($result);
		else if($format == 'object') return mysql_fetch_object($result);
		else if($format == 'value') {
			if ( mysql_num_rows( $result ) > 0 )
				return mysql_result($result, 0);
			else
				return false;
		}
		else if($format == 'count') return mysql_num_rows($result);
		else if($format == 'compress') return $this->compress($result);


	}

	/**
	 * @return iterator Iterator of objects matching the query.
	 */
	function selectAll( $query ) {
		$result = $this->query( $query );

		$rtn = array();
		while ( $obj = mysql_fetch_object( $result ) ) {
			$rtn[] = $obj;
		}
		// If we later want to iterate large result sets, this iterator could
		// do the iteration on demand
		return new \ArrayIterator( $rtn );
	}

	/* ---------------------------------------

	INSERT

	--------------------------------------- */

	function insert($table, $array, $id = false)
	{

		// Clean and validate the array
		$array = $this->clean($array);

		// Validate each value
		foreach($array as $key => $value){

			$array[$key] = $value;

		}

		// Compact array to strings
		$keys = implode(',', array_keys($array));
		$values = implode(',', array_values($array));

		// Execute query
		$result =  $this->query("INSERT INTO $table ($keys) VALUES ($values)");

		// Return insert id if needed
		if($id === true && $result !== false) return mysql_insert_id($this->db_connection);

		// Return true on success
		return $result;

	}



	/* ---------------------------------------

	DELETE

	--------------------------------------- */

	function delete($table, $where)
	{

		// Execute query
		return $this->query("DELETE FROM $table WHERE $where");

	}




	/* ---------------------------------------

	UPDATE

	--------------------------------------- */

	function update($table, $array, $where)
	{

		// Clean and validate the array
		$array = $this->clean($array);

		// Array for name value pairs
		$changes = array();

		// Go through the array
		foreach($array as $key => $value){

			$changes[] = "$key=$value";

		}

		// Compact array to strings
		$changes = implode(',', $changes);

		// Execute query
		return $this->query("UPDATE $table SET $changes WHERE $where");


	}

	function getUpdatedRowsCount() {
		return mysql_affected_rows();
	}




	/* ---------------------------------------

	APPEND

	--------------------------------------- */

	function append($table, $array, $where, $position = -1)
	{

		// Array for name value pairs
		$changes = array();

		// Go through the array
		foreach($array as $key => $value){

			// No position supplied
			if($position == -1){

				// Buid the update text
				$valid = $this->validate($value);
				$changes[] = "$key=(CASE WHEN $key IS NULL THEN  $valid ELSE CONCAT($key, ',', $valid) END) ";


			} else {

				$current = $this->select("SELECT $key FROM $table WHERE $where", 'value');

				// Check for null
				if($current == ''){

					// Create empty array
					$current = array();

				} else {

					// Create array from values
					$current = explode(',', $current);

				}

				// Place value at the end
				if($position >= count($current)){

					$current[] = $value;
					$changes[] = "$key=" . $this->validate(implode(',', $current));

				}

				// Insert value at desired position
				else {

					array_splice($current, $position, 0, $value);
					$changes[] = "$key=" . $this->validate(implode(',', $current));

				}

			}


		}

		// Compact array to strings
		$changes = implode(',', $changes);

		// Execute query
		return $this->query("UPDATE $table SET $changes WHERE $where");


	}



	/* ---------------------------------------

	ADD

	--------------------------------------- */

	function add($table, $column, $values, $where, $position = -1){

		// Get Value from table
		$query = "SELECT $column FROM $table WHERE $where";
		$result = $this->select( $query , 'value');

		// Break into array, use preg_split to avoid empty elements
		$contents = $this->split( $result );
		$values = is_string( $values ) ? $this->split( $values ) : $values;

		// Splice in the new values
		array_splice( $contents, $position, 0, $values );

		// Ensure array is unique
		$contents = array_unique( $contents );

		// Update new value
		$this->update($table, array($column =>  implode( ',', $contents ) ), $where);

	}



	/**
	 *
	 * Removes the specified value from a CSV column.
	 * The comparission is currently case sensitive.
	 *
	 * @param string $table
	 * @param string $column
	 * @param mixed $value
	 * @param string $where
	 * @return void
	 *
	 */

	public function remove( $table, $column, $values, $where )
	{

		// Get Value from table
		$query = "SELECT $column FROM $table WHERE $where";
		$result = $this->select( $query , 'value');

		// Break into array
		$contents = $this->split( $result );
		$values = is_string( $values ) ? $this->split( $values ) : $values;

		// Remove values from the existing contents array
		$result = array_diff($contents, $values);

		// Update new value
		$this->update($table, array($column =>  implode( ',', $result ) ), $where);

	}


	/**
	 *
	 * Removes a single value from a CSV column.
	 * Can remove the value from multiple rows.
	 *
	 * @param string $table
	 * @param string $column
	 * @param mixed $value
	 * @param string $where
	 * @return void
	 *
	 */

	public function removeFromRows( $table, $column, $value, $where )
	{

		$case = "(CASE WHEN $column LIKE '%,$value,%' THEN REPLACE($column, ',$value,', ',') WHEN $column LIKE '$value,%' THEN REPLACE($column, '$value,', '') WHEN $column LIKE '%,$value' THEN REPLACE($column, ',$value', '') END)";


		// Update new value
		$this->update($table, array( 'xx_' .$column =>  $case ), $where);

	}





	/* ---------------------------------------

	PREPEND

	--------------------------------------- */

	function prepend($table, $array, $where)
	{

		// Array for name value pairs
		$changes = array();

		// Go through the array
		foreach($array as $key => $value){

			// Check the value
			$valid = $this->validate($value);

			// Buid the update text
			$changes[] = "$key=(CASE WHEN $key IS NULL THEN  $valid ELSE CONCAT($valid,',',$key) END) ";

		}

		// Compact array to strings
		$changes = implode(',', $changes);

		// Execute query
		return $this->query("UPDATE $table SET $changes WHERE $where");


	}




	/* ---------------------------------------

	INCREMENT

	--------------------------------------- */

	function increment($table, $column, $where, $amount = 1)
	{

		// Execute query
		return $this->query("UPDATE $table SET $column = $column + $amount WHERE $where");


	}




	/* ---------------------------------------

	COMPRESS

	--------------------------------------- */

	private function compress($result)
	{

		$rows = mysql_num_rows($result);
		$fields = mysql_num_fields($result);
		$a = array();

		// Go through rows and place in arrays
		for($i = 0; $i < $rows; $i++){

			for($j = 0; $j < $fields; $j++){

				$n = mysql_field_name($result, $j);
				$a[$n] .= ($i == 0) ? mysql_result($result, $i, $n) :  '^'.mysql_result($result, $i, $n);

			}

		}

		return $a;

	}



	/* ---------------------------------------

	VISIT

	--------------------------------------- */

	function visit($visit_flash = '', $visit_referer = '')
	{

		$a = array();
		$a['user_id'] = $this->user_id;
		$a['visit_flash'] = $visit_flash;
		$a['visit_referer'] = substr($visit_referer, 0, 511);
		$a['visit_agent'] = $_SERVER['HTTP_USER_AGENT'];
		$a['visit_ip'] = $_SERVER['REMOTE_ADDR'];

		$this->insert('visits', $a);

	}




	/* ---------------------------------------

	ERROR

	--------------------------------------- */

	function error($context, $description, $code = 0, $email = false)
	{

		$a = array(
			'user_id' => $this->user_id,
			'error_source' => $this->app_id,
			'error_description' => $description,
			'error_context' => $context,
			'error_code' => $code,
			'error_server' => @$_ENV["COMPUTERNAME"],
			'error_agent' => @$_SERVER['HTTP_USER_AGENT']
		);

		$this->insert('errors', $a);

		if($email){

			$a = array(
				'email_from' => '"DPHOTO Alert" <alerts@dphoto.com>',
				'email_to' => '"DPHOTO Alert" <alerts@dphoto.com>',
				'email_subject' => "DPHOTO Error - $context",
				'email_message' => "$context Error $code \n\n $description"
			);

			$this->insert('emails', $a);

		}

	}




	/* ---------------------------------------

	TRACE

	--------------------------------------- */

	function trace($context, $description, $code = 0)
	{

		$a = array();
		$a['user_id'] = $this->user_id;
		$a['trace_source'] = $this->parent;
		$a['trace_description'] = $description;
		$a['trace_context'] = $context;
		$a['trace_code'] = $code;

		$this->insert('traces', $a);

	}



	/* ---------------------------------------

	CLEAN

	Takes field => value array from update and
	insert and cleans the values. Allows sql
	functions to be passed in by using xx_ at
	the start of field name.

	--------------------------------------- */

	private function clean( $array ) {

		$clean = array();

		foreach ( $array as $key => $value ) {

			// Check for SQL function values
			if ( preg_match( '/^xx\_/', $key ) ) {

				$column = preg_replace( '/^xx\_/', '', $key );
    			$clean[$column] = $value;

			}

			// Normal Value (needs to be sanitised)
			else {

				// If array, convert to String
				if( is_array($value) ) $value = implode( ',', $value );

				$clean[$key] = $this->validate( $value );

			}

		}

		return $clean;

	}



	/* ---------------------------------------

	VALIDATE

	--------------------------------------- */

	public function validate($string, $quote = true)
	{

		// Convert boolean to 0 or 1 for db
		if(is_bool($string)) $string = (integer) $string;

		// Take out any ^ characters
		$string = str_replace("^", ' ', $string);

		// Replicate mysql_real_escape_string function
		$string = str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $string);

		// When quotes not needed
		if($quote == false) return $string;

		// Convert to NULL if empty
		if(trim($string) == '' || $string == NULL) return 'NULL';

		// Return quoted string
		return "'".$string."'";

	}



	/**
	 *
	 * Takes a string and converts it to an array
	 * while ensuring empty elements aren't created
	 *
	 */

	public function split( $string, $delimiter = ',' )
	{

		return preg_split("/{$delimiter}/", $string, 0, PREG_SPLIT_NO_EMPTY);

	}



	/* ---------------------------------------

	HISTORY

	--------------------------------------- */

	public function history()
	{

		return $this->queries;

	}



}

?>
