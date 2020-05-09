<?php
/******

FULL DOCUMENTATION available at:

http://jacobewing.github.io/dbTemplate/

This class is used for simplifying the handling of database records.  Any
record can be retreived as an object, its values manipulated, etc.  Data is
scrubbed in the dbTemplate class to prevent SQL injections when it gets saved.
tl;dr: It's a simple ORM of sorts

The general behavior is that static methods are performed on the table
generally, and instance methods are applied to the records themselves.



TODO::::

In the record linking structure, add a "filterby" parameter, which adds a where condition on the link
that could be like so:

"filterby" => array(
	'category' => 'foo',
	'groupid' => 'this::id'
)

the first item would add a "`category` = 'foo'" condition to the where clause
the second item would add "`groupid` = $this->getField('id')";
*****/


/* Need to do one of these two things:
	- add an error check, throwing an exception on any classes that have
	  more than one "auto" field
	- drop the auto functionality altogether, instead adding a "refresh"
	  tag or just refreshing all fields
*/


/*

 - !! Really need to re-structure the way results are handled.  I think results
   need to be a class of their own.  They could store a 2d array of key values,
   each row retrieveable as a record object.  That may be a bit tricky since
   parenthetical operators can't be overloaded.  It would be nice though to be
   able to do something like:

	 $results = $table->getByCategory(1);
	 $results->setCategory(2);
	 $results->save();

    With results being a group of records instead of a single one.

    Hmm - individual records could be accessed with something like:

    foreach($results->getRecords() as $record){
    	$record->stuff();
    }

    or maybe:

    while($record = $results->pop()){
    	$record->stuff();
    }

    Actually, I'll have to consider handling that by simply extending the
    dbTemplate class.  It might be as simple as switching $_data to be an array
    of records containing its current structure.  The count of $_data would be
    the number of records handled.  Obviously functions would need to be
    updated for that.

    Hm - so yeah.  It might just be a way of changing my way of thinking of
    what a record represents.  All of the functionality that's there now can be
    applied the same way, but also to multiple records if we have those.

 - ensure that all field flags are case-insensitive

 - refactor nested links to generate a single query to retrieve all related records

 - improve the static "search" function, adding a parameter which is an array
   of fields that should be searched.  If null, then all fields are searched.
   if string, then that field is searched (fieldname or alias).  if an array,
   then any field listed in that array should be searched.
*/
abstract class dbTemplate{
	protected $_data;
	protected $_fields;
	protected $_keys;
	protected $_aliasMap;
	protected $_isNewRecord;
	protected $_links;
	protected $_foreignfields;
	protected $_mysqli;
	static protected $mysqli;
	const MAX_LINK_RECURSION = 10;

	function __construct(){
		$this->_initialize();
		$this->_postInitialize();

		$this->reset();
		$this->_mysqli = static::$mysqli;

		$numArgs = func_num_args();
		$args = func_get_args();
		$numExpectedArgs = count($this->_keys);

// for future consideration.  As-is, this just takes the values passed in, and
// passes them along to "load", which assumes those are the key fields, listed
// in order.  It may be nice to change that so that it checks the keys,
// validates them, and then searches for a record that matches them; returning
// that or a new record with those values assigned, depending on the
// cirumstance.
		if($numArgs == 1 && is_array($args[0])){
			if(count($args[0]) == $numExpectedArgs){
				$this->load(array_values($args[0]));
			}else{
				$errMsg = get_class($this) . " construct expects $numExpectedArgs parameter" . ($numExpectedArgs == 1 ? '' : 's');
				$errMsg .= " (" . implode(', ', $this->_keys);
				$errMsg .= "), received " . count($args[0]) . " instead";
			}
		}else if($numArgs == $numExpectedArgs){
			$this->load($args);
		}else if($numArgs != 0){
			$errMsg = get_class($this) . " construct expects $numExpectedArgs parameter" . ($numExpectedArgs == 1 ? '' : 's');
			$errMsg .= " (" . implode(', ', $this->_keys);
			$errMsg .= "), received $numArgs instead";
			throw new Exception($errMsg);
		}

		if($this->isNewRecord() && method_exists($this, '_onNewRecord')){
			$this->_onNewRecord();
		}
	}

	public static function connect($host, $user, $password, $database){
		$mysqli = new mysqli($host, $user, $password, $database);
		if($mysqli->connect_errno){
			throw new Exception("dbTemplate::connect: Database connection failed: " . $mysqli->connect_errno);
		}
		static::$mysqli = $mysqli;
	}

	// loop through the $structure array defined in the child class, and
	// build our internal data from that
        protected function _initialize(){
		$className = get_called_class();
		$definitions = array(
			'name' => '_tableName',
			'keys' => '_keys',
			'fields' => '_fields',
			'links' => '_links',
			'foreignfields' => '_foreignfields'
		);
		foreach($definitions as $name => $internalName){
			if(array_key_exists($name, $className::$structure)){
				$this->$internalName = $className::$structure[$name];
			}else{
				$this->$internalName = array();
			}
		}
        }

	// massage the initialization specs, handling aliases, max string lengths
	private function _postInitialize(){
		foreach($this->_fields as $fieldname => &$field){
			if(preg_match('/^VARCHAR\s*\(\s*\d+\s*\)/i', $field['type'])){
				$parts = preg_split('/[\(\)]/', $field['type']);
				$field['type'] = 'VARCHAR';
				$field['maxlength'] = intval($parts[1]);
			}

			if(preg_match('/^DECIMAL\s*\(\s*\d+\s*,\s*\d+\s*\)/i', $field['type'])){
				$parts = preg_split('/[,\(\)]/', $field['type']);
				$digits = intval($parts[1]);
				$decimals = intval($parts[2]);
				if($digits < $decimals || $digits < 0 || $decimals < 0){
					throw new Exception("dbTemplate::_postInitialize: Invalid DECIMAL format for field '$fieldName': " . $field['type']);
				}
				$field['decimalformat'] = array(
					'left' => $digits - $decimals,
					'right' => $decimals
				);
				$field['type'] = 'DECIMAL';

			}

			if(!array_key_exists('default', $field)){
				$field['default'] = null;
			}
		}
		$this->_aliasMap = $this->getAliasMap();

		$this->_foreignfields = array_change_key_case($this->_foreignfields, CASE_LOWER); // <-- change field link keys to lower case, allowing case-insensitivity
	}

	// reset this record to a blank one
	public function reset(){
		foreach($this->_fields as $fName => $fData){
			$this->_data[$fName] = array_key_exists('default', $fData) ? $fData['default'] : null;
		}

		// scrub table links and define the array if it doesn't exists
		$newLinks = array();
		foreach($this->_links as $key => $val){
			$newLinks[strtolower(trim($key))] = $val;
		}
		$this->_links = $newLinks;
		$this->_isNewRecord = true;
	}
	public static function getFields(){
		$className = get_called_class();
		return $className::$structure['fields'];
	}
	public static function deleteMultiple($records){
		$className = get_called_class();
		if(!is_array($records)){
			throw new Exception("dbTemplate::deleteMultiple: first parameter should be an array of records to delete");
		}

		foreach($records as $record){
			if(!is_object($record)){
				$record = new $className($record);
			}
			$record->delete();
		}
	}

	// delete this record from the database and reset the object
	public function delete(){

		if(method_exists($this, '_preDelete')){
			if($this->_preDelete() === false){
				return;
			}
		}

		if(!$this->_isNewRecord){
			$query = "DELETE FROM `" . $this->_tableName . "` WHERE ";
			$queryParts = array();
			foreach($this->_keys as $keyField){
				$queryParts[] .= "`$keyField` = '" . $this->_data[$keyField] . "'";
			}
			$query .= implode(' AND ', $queryParts);
			if(!$this->query($query)){
				throw new Exception("Unable to delete record: {$this->_mysqli->error}\nFailed query: $query");
			}
		}
		$this->reset();

		if(method_exists($this, '_postDelete')){
			$this->_postDelete();
		}
	}

	public static function truncate($confirmation = null){
		/*** USE WITH EXTREME CARE!!! This truncates the table ***/

		$className = get_called_class();
		$tableName = $className::$structure['name'];

		if(
			is_array($confirmation)
			&& count($confirmation) == 1
			&& key($confirmation) == 'confirm'
			&& $confirmation['confirm'] === true
		){
			dbTemplate::query('TRUNCATE TABLE ' . $tableName);
		}else{
			throw new Exception($className . '::truncate($confirmation) requires one parameter, which should be an array with a single key => value pair.  The key must be the string "confirm", and the value must be true (not a value that evaluates as true, but the actual value true).  Use with caution.');
		}
	}

	// build our map of aliases from the initial _fields data
	protected static function getAliasMap(){
		$className = get_called_class();
		if(array_key_exists('aliasMap', $className::$structure)){
			return $className::$structure['aliasMap'];
		}

		$aliasMap = array();
		foreach($className::$structure['fields'] as $fName => $fData){
			if(array_key_exists('alias', $fData)){
				$aliasKey = strtolower($fData['alias']);
			}else{
				$aliasKey = strtolower($fName);
			}
			if(array_key_exists($aliasKey, $aliasMap)){
				throw new Exception("dbTemplate::getAliasMap: duplicate field or alias \"$aliasKey\"");
			}
			$aliasMap[$aliasKey] = $fName;
		}

		return $className::$structure['aliasMap'] = $aliasMap;
	}

	// scrub the data in the specified field
	public static function scrubValue($value, $fieldName){
		$className = get_called_class();
		$fieldDef = $className::$structure['fields'][$fieldName];

		$rval = null;
		$canBeNULL = true;
		if(array_key_exists('notnull', $fieldDef)){
			$canBeNULL = ($fieldDef['notnull'] == false);
		}
		if($canBeNULL && $value === null){
			return $rval;
		}
		$fieldType = strtoupper($fieldDef['type']);
		switch($fieldType){
			case 'INT': case 'INTEGER':
				$rval = intval($value);
				break;
			case 'TIMESTAMP':
				if($value == 'NOW()'){
					$rval = 'NOW()';
				}else{
					$timeStamp = strtotime($value);
					$rval = date('Y-m-d H:i:s', $timeStamp < 1 ? 1 : $timeStamp);
				}
				break;
			case 'DATETIME':
				if($value == 0) $rval = null;
				if($value != null){
					$rval = date('Y-m-d H:i:s', strtotime($value));
				}
				break;
			case 'DECIMAL':
				if(array_key_exists('rounding', $fieldDef)){
					$digits = intval($fieldDef['rounding']);
					$rval = number_format(floatval($value), $digits, '.', ',');
				}else{
					$rval = floatval($value);
				}
				break;
			case 'FLOAT':
				$rval = floatval($value);
				break;
			case 'BOOLEAN':
				$rval = $value ? 1 : 0;
				break;
			case 'VARCHAR': case 'TEXT': case 'DATE': case 'TIME':
				$rval = dbTemplate::$mysqli->real_escape_string(mb_convert_encoding($value, 'UTF-8'));
				break;
			case 'ENUM':
				if(in_array($value, $fieldDef['values'])){
					$rval = dbTemplate::$mysqli->real_escape_string($value);
				}
				break;
			case 'JSON':
				$rval = dbTemplate::$mysqli->real_escape_string(mb_convert_encoding(json_encode($value), 'UTF-8'));
				break;
			default:
				$rval = dbTemplate::$mysqli->real_escape_string(mb_convert_encoding($value, 'UTF-8'));
		}

		if(array_key_exists('unsigned', $fieldDef)){
			if(in_array($fieldType, array('INT', 'INTEGER', 'DECIMAL', 'FLOAT'))){
				$rval = abs($rval);
			}
		}

		return $rval;
	}

	// get a list of all allowed values for a field that has a restricted set (ENUM or BOOLEAN at this point)
	public static function allowedValues($fieldName){
		$rval = null;
		$className = get_called_class();
		$aliasMap = $className::getAliasMap();
		$fieldName = strtolower($fieldName);
		if(array_key_exists($fieldName, $aliasMap)){
			$fieldName = $aliasMap[$fieldName];
		}

		if(!array_key_exists($fieldName, $className::$structure['fields'])){
			throw new Exception("$className::allowedValues(): Invalid field name \"$fieldName\"");
		}else{
			$definition = $className::$structure['fields'][$fieldName];
			$fieldType = strtoupper($definition['type']);
			switch($fieldType){
				case 'ENUM':
					$rval = $definition['values'];
					break;
				case 'BOOLEAN':
					$rval = array(0, 1);
					break;
				default:
					throw new Exception("$className::allowedValues(): This function is only callable on ENUM or BOOLEAN field types - called on $fieldType");
			}
		}
		return $rval;
	}

	// handle dynamic static functions like <class>::getBy<field>(<value>);
	public static function __callStatic($funcName, $args){
		$className = get_called_class();
		$rval = null;
		if(strtolower(substr($funcName, 0, 5)) == 'getby'){
			if(count($args) > 1){
				$valueList = $args;
			}else if(is_array($args[0])){
				$valueList = $args[0];
			}else{
				$valueList = array($args[0]);
			}

			$results = array();
			foreach($valueList as $value){
				$val = $className::getByField(substr($funcName, 5), $value);
				if($val != null){
					$results[] = $val;
				}
			}
			if(count($results) == 1){
				$rval = $results[0];
			}else if(count($results) > 1){
				$rval = $results;
			}
		}else if(strtolower(substr($funcName, 0, 11)) == 'deletewhere' && strtolower(substr($funcName, -2)) == 'in'){
			$fieldName = strtolower(substr($funcName, 11, -2));
			$aliasMap = $className::getAliasMap();
			if(!array_key_exists($fieldName, $aliasMap)){
				throw new Exception("dbTemplate::__callStatic($funcName): invalid field name '$fieldName'");
			}
			if(count($args) > 1){
				$valueList = $args;
			}else if(is_array($args[0])){
				$valueList = $args[0];
			}else{
				$valueList = array($args[0]);
			}
			// This could get better performance by wrapping this for loop in an if
			// checking for the _predelete function.  If it's there, do this, if not, build
			// a single mass SQL delete.
			foreach($valueList as $val){
				$valResult = $className::getByField($fieldName, $val);
				if(is_object($valResult)){
					$valResult = array($valResult);
				}else if(!is_array($valResult)){
					continue;
				}
				foreach($valResult as $record){
					try{
						$record->delete();
					}catch(Exception $e){
						// it's possible that the delete will fail in some cases (classes with
						// predeletes that throw an exception).  In such cases, we want to allow it to
						// continue gracefully.
					}
				}
			}

		}else{
			// Could also add a get<Field> and set<Field> function that updates one particualr
			// field on all records.  May not be that useful though.

			throw new Exception("dbTemplate::$funcName is not defined.");
		}
		return $rval;
	}

	// NOT DOCUMENTED  Does some basic scrubbing on a record object
	public function __clone(){
		foreach($this->_keys as $key){
			$this->resetField($key);
		}
		$this->_isNewRecord = true;
	}

	// NOT DOCUMENTED Resets the value of the specified field back to its default value
	public function resetField($fieldname){
		if(!array_key_exists($fieldname, $this->_fields)){
			throw new Exception("dbTemplate::resetField: Invalid field name " . $fieldname);
		}
		$fData = $this->_fields[$fieldname];
		$this->_data[$fieldname] = array_key_exists('default', $fData) ? $fData['default'] : null;
	}

	// find the record(s) that this one links to based on the links
	// $definition should be the array that defines the link.  This is passed in to allow for recursion
	public function linkedRecords($definition, $maxRecursion = self::MAX_LINK_RECURSION){
		if($maxRecursion <= 0){
			throw new Exception("dbTemplate::linkedRecords: maximum recursion depth reached!");
		}

		$flags = array(
			'force_array' => false,
			'allow_duplicates' => false
		);

		// any special flags set in the definition can be noted now.
		if(array_key_exists('flags', $definition)){
			if(!is_array($definition['flags'])){
				throw new Exception("dbTemplate::linkedRecords: flags parameter should be defined as an array");
			}
			foreach($definition['flags'] as $flag){
				if(array_key_exists($flag, $flags)){
					$flags[$flag] = true;
				}else{
					throw new Exception("dbTemplate::linkedRecords: invalid flag, '$flag'");
				}
			}
		}

		$conditions = array('TRUE');
		foreach($definition['linkfields'] as $thisField => $thatField){
			$condition = '`' . $this->_mysqli->real_escape_string($thatField);
			$condition .= "` = '" . $this->_mysqli->real_escape_string($this->_data[$thisField]) . "'";
			$conditions[] = $condition;
		}
		$tableName = $definition['class']::$structure['name'];
		$query = "SELECT * FROM `" . $tableName . "` WHERE " . implode(' AND ', $conditions);
///////////// NOT DOCUMENTED!!!!  the orderby definition element
		if(array_key_exists('orderby', $definition)){
			$query .= " ORDER BY " . $this->_mysqli->real_escape_string($definition['orderby']);
		}
		$results = $this->query($query);

		// we won't put any count limit on the query, but instead
		// return an array of records of there's more than one
		if($results->num_rows == 0){
			$rval = null;
		}else if($results->num_rows == 1){
			$rval = new $definition['class']();
			$rval->setData($results->fetch_assoc(), array('noalias'));
			$rval->setNewRecord(false);
			if(array_key_exists('childlink', $definition)){
				$rval = $rval->linkedRecords($definition['childlink'], $maxRecursion - 1);
			}
		}else if($results->num_rows > 1){
			$rval = array();
			while($row = $results->fetch_assoc()){
				$obj = new $definition['class']();
				$obj->setData($row, array('noalias'));
				$obj->setNewRecord(false);
				if(array_key_exists('childlink', $definition)){
					$children = $obj->linkedRecords($definition['childlink'], $maxRecursion - 1);
					if(is_array($children)){
						$rval = array_merge($rval, $children);
					}else if(is_object($children)){
						$rval[] = $children;
					}
				}else{
					$rval[] = $obj;
				}
			}
			if(!$flags['allow_duplicates']){
				$rval = array_unique($rval);
			}
		}else{
			// shouldn't be possible, but just in case...
			throw new Exception('dbTemplate::__call::default: weird result: num_rows = ' . $results->num_rows);
		}
		if($flags['force_array'] && !is_array($rval)){
			$rval = $rval == null ? array() : array($rval);
		}

		if(array_key_exists('post_fetch', $definition)){
			$className = get_class_name();
			$rval = $classname::$definition['post_fetch']($rval);
		}
		return $rval;
	}

	// retrieve the value of a field or link specified by name or alias
	public function getField($name){
		$rval = null;

		// first, look for an aliased field name that matches $name:
		if(array_key_exists($name, $this->_aliasMap)){
			if(array_key_exists('gethandler', $this->_fields[$this->_aliasMap[$name]])){
				// fixme... This will not handle the case where "gethandler" points to
				// an undefined function.  It may give infinite recursion as a result.
				$rval = $this->{$this->_fields[$this->_aliasMap[$name]]['gethandler']}($params);
			}else{
				$rval = $this->_data[$this->_aliasMap[$name]];
			}

		// ok, let's see if we can find it as a raw field name
		}else if(array_key_exists($name, $this->_fields)){
			if(array_key_exists('gethandler', $this->_fields[$name])){
				$rval = $this->{$this->_fields[$name]['gethandler']}();
			}else{
				$rval = $this->_data[$name];
			}

		// maybe we can find it as a linked record
		}else if(array_key_exists($name, $this->_links)){
			$rval = $this->linkedRecords($this->_links[$name]);

		// ok, perhaps a linked field?
		}else if(array_key_exists($name, $this->_foreignfields)){
			$def = $this->_foreignfields[$name];
			$obj = $this->linkedRecords($this->_links[$def['link']]);
			if(is_array($obj)){
				$rval = array();
				foreach($obj as $o){
					$rval[] = $o->getField($def['field']);
				}
			}else if(is_object($obj)){
				$rval = $obj->getField($def['field']);
			}

		// no dice
		}else{
			throw new Exception(get_class($this) . "::getField(): Invalid field name \"$name\"");
		}

		return $rval;
	}

	// handle various calls that use the field names (e.g. getId(), setId(), etc.)
	public function __call($functionName, $params){
		$func = strtolower(trim($functionName));
		// if this exists in our _links array, then find the corresponding record(s).
		if(array_key_exists($func, $this->_links)){
			return $this->linkedRecords($this->_links[$func]);
		}else if(array_key_exists($func, $this->_aliasMap)){
			return $this->getField($func);
		}else if(in_array($func, $this->_aliasMap)){
			return $this->getField($func);
		}else if(array_key_exists($func, $this->_foreignfields)){
			return $this->getField($func);
		}else{
			$prefix = substr($func, 0, 3);
			if($prefix == 'get'){
				$name = substr($func, 3);
				return $this->getField($name);
			}else if($prefix == 'set'){
				$name = substr($func, 3);
				if(count($params) != 1){
					throw new Exception("function " . get_class($this) . "::set$name expects a single value parameter");
				}
				$this->setField($name, $params[0]);
			}else{
				throw new Exception("dbTemplate::__call: call to non-existant member function: " . $functionName);
			}
		}
	}

	public function setField($field, $value){
		$thisField = null;
		if(array_key_exists($field, $this->_aliasMap)){
			$thisField = $this->_aliasMap[$field];
		}else if(array_key_exists($field, $this->_fields)){
			$thisField = $field;
		}
		if($thisField != null){

			// call the custom validator if defined.  It's expected to throw an exception if the field value is invalid
			if(array_key_exists('validator', $this->_fields[$thisField])){
				$this->{$this->_fields[$thisField]['validator']}($value);
			}
			// if a custom scrubber is defined, it should return a clean version of data passed in
			if(array_key_exists('scrubber', $this->_fields[$thisField])){
				$value = $this->{$this->_fields[$thisField]['scrubber']}($value);
			}


			// if a custom function for handling a set on this variable is defined, then call it
			if(array_key_exists('sethandler', $this->_fields[$thisField])){
				// fixme... This will not handle the case where "sethandler" points to
				// an undefined function.  It may give infinite recursion as a result.
				return $this->{$this->_fields[$thisField]['sethandler']}($value);
			}else{
				if($value === null){
					if(array_key_exists('notnull', $this->_fields[$thisField])){
						throw new Exception("NULL value not allowed for field $field");
					}
					return $value;
				}
				// validate ENUMS
				if($this->_fields[$thisField]['type'] == 'enum'){
					if(!in_array($value, $this->_fields[$thisField]['values'])){
						throw new Exception("field $field is an ENUM and expects one of the following values: " . implode(', ', $this->_fields[$thisField]['values']));
					}
				}

				// validate integer fields
				if(in_array($this->_fields[$thisField]['type'], array('INT', 'INTEGER'))){
					if(!preg_match('/^[0-9+-]*$/', $value)){
						throw new Exception("field $field expects an integer value");
					}
				}

				// validate VARCHAR with a defined length
				if(array_key_exists('maxlength', $this->_fields[$thisField])){
					if(strlen($value) > $this->_fields[$thisField]['maxlength']){
						throw new Exception("value '{$value}' exceeds maximum field length of " . $this->_fields[$thisField]['maxlength']);
					}
				}
				// handle decimal restrictions if applicable
				if($this->_fields[$thisField]['type'] == 'DECIMAL'){
					if(array_key_exists('decimalformat', $this->_fields[$thisField])){
						if(strlen(intval($value)) > $this->_fields[$thisField]['decimalformat']['left']){
							throw new Exception("dbTemplate::setField: value exceeds left digit limit of " . $this->_fields[$thisField]['decimalformat']['left']);
						}
						$value = round(floatval($value), $this->_fields[$thisField]['decimalformat']['right']);
					}
				}

				// handle absolute values
				if(array_key_exists('unsigned', $this->_fields[$thisField])){
					if(is_numeric($value)){
						$value = abs($value);
					}
				}

				// hey!  If we made it this far, then the value seems valid and we can store it
				$this->_data[$thisField] = $value;
				return $value;
			}
		}else if(array_key_exists($field, $this->_links)){

			$objType = gettype($value);
			if($objType != 'object'){
				throw new Exception("dbTemplate::setField: expecting record of class '" . $this->_links[$field]['class'] . "', instead received one of type $objType");
			}
			$objClass = get_class($value);
			if($objClass != $this->_links[$field]['class']){
				throw new Exception("dbTemplate::setField: expecting record of class '" . $this->_links[$field]['class'] . "', instead received one of type $objClass");
			}

			// no exception?  Ok, we can assign the necessary link fields.
			foreach($this->_links[$field]['linkfields'] as $myField => $theirField){
				$this->_data[$myField] = $value->{'get' . $theirField}();
			}
		}else{
			throw new Exception(get_class($this) . "::set$field: Invalid field name \"$field\"");
		}
	}

	// make the object's string value a json object string
	function __tostring(){
		return json_encode($this->getData(true));
	}

	// save the record, and update accordingly if it's a newly created one.
	public function save(){
		if(method_exists($this, '_preSave')){
			$this->_preSave();
		}

		if($this->_isNewRecord){
			if(method_exists($this, '_preCreate')){
				$this->_preCreate();
			}

			// we're creating a new record
			$query = "INSERT INTO `" . $this->_mysqli->real_escape_string($this->_tableName) . "`";
			$fieldList = array();
			$valueList = array();
			foreach($this->_fields as $fName => $fStruct){
				// if it's not an auto-increment or the value is not the default for this field, then we insert this field
				if(!array_key_exists('auto', $fStruct) || $this->_data[$fName] != $fStruct['default']){
					$fieldList[] = $this->_mysqli->real_escape_string($fName);
					$val = $this->scrubValue($this->_data[$fName], $fName);
					if($val === null){
						$valueList[] = 'NULL';
					}else if(strtoupper($val) == 'NOW()' && strtoUpper($fStruct['type']) == 'TIMESTAMP'){
						$valueList[] = 'NOW()';
					}else{
						$valueList[] = "'$val'";
					}
				}
			}
			$query .= " (`" . implode('`, `', $fieldList) . "`)";
			$query .= " VALUES (" . implode(", ", $valueList) . ")";

			if(!$this->query($query)){
				throw new Exception("Unable to create new record: " . $this->_mysqli->error . "\n" . $query . "\n");
			}

			// reload to pick up auto-increments, NOW(), and other automatic field values
			$this->_isNewRecord = false;

			foreach($this->_fields as $fName => $fStruct){
				if(array_key_exists('auto', $fStruct) && $fStruct['auto'] == true){
					$this->_data[$fName] = $this->_mysqli->insert_id;
				}
			}

			$this->refresh();

			if(method_exists($this, '_postCreate')){
				$this->_postCreate();
			}

			// this function is badly named, and is here for legacy
			// purposes only.  It should not be used.  Instead, use
			// "_postCreate()", which serves the same purpose.
			if(method_exists($this, '_oncreate')){
				$this->_oncreate();
			}

		}else{
			if(method_exists($this, '_preUpdate')){
				$this->_preUpdate();
			}
			// we're updating an existing record
			$query = "UPDATE " . $this->_mysqli->real_escape_string($this->_tableName) . " SET ";
			$queryParts = array();
			foreach($this->_fields as $fName => $fStruct){
				// if it's not an auto-increment or the value is not the default for this field, then we insert this field
				if(!array_key_exists('auto', $fStruct) || $this->_data[$fName] != $fStruct['default']){
					$val = $this->scrubValue($this->_data[$fName], $fName);
					if($val === null){
						$queryParts[] = "`$fName` = NULL";
					}else{
						$queryParts[] = "`$fName` = '" . $this->scrubValue($this->_data[$fName], $fName) . "'";
					}
				}
			}
			$query .= implode(', ', $queryParts);

			$query .= " WHERE ";
			$queryParts = array();
			foreach($this->_keys as $keyField){
				$queryParts[] .= "`$keyField` = '" . $this->scrubValue($this->_data[$keyField], $keyField) . "'";
			}
			$query .= implode(' AND ', $queryParts);
			if(!$this->query($query)){
				throw new Exception("Unable to update record: " . $this->_mysqli->error);
			}

			if(method_exists($this, '_postUpdate')){
				$this->_postUpdate();
			}

			// this function is badly named, and is here for legacy
			// purposes only.  It should not be used.  Instead, use
			// "_postUpdate()", which serves the same purpose.
			if(method_exists($this, '_onupdate')){
				$this->_onupdate();
			}
		}

		if(method_exists($this, '_postSave')){
			$this->_postSave();
		}
	}

	// refresh the field values in this record.  Used after a call to save() on a
	// new record so that automatic fields (e.g. TIMESTAMP NOW()), can be loaded
	// back into the object.
	public function refresh(){
		if($this->_isNewRecord) return;
		$keyVals = array();
		for($n = 0; $n < count($this->_keys); $n++){
			$keyVals[$n] = $this->_data[$this->_keys[$n]];
		}
		$this->load($keyVals);
	}

	// load a record using its primary keys
	public function load($keyVals){
		if(!is_array($keyVals)) $keyVals = array($keyVals);
		$conditions = array();

		for($n = 0; $n < count($this->_keys); $n++){
			$conditions[] = "`" . $this->_mysqli->real_escape_string($this->_keys[$n]) . "` = '" . $this->scrubValue($keyVals[$n], $this->_keys[$n]) . "'";
		}
		$query = "SELECT * FROM `" . $this->_tableName . "` WHERE " . implode(' AND ', $conditions);
		$result = $this->query($query);

		// this is a bit of a bug that needs to be fixed:
		// this fetch_assoc will throw an exception if there are no rows.  That means
		// the else below will never be met.
		// Instead, this should use a try-catch, with the catch throwing the
		// explanatory exception.  Note that some code depends on this exception, so
		// simply ploughing ahead with a new record is not acceptable.
		$row = $result->fetch_assoc();
		if($row){
			$this->_isNewRecord = false;
			foreach($this->_fields as $fName => $fData){
				if(strtoupper(trim($fData['type'])) == 'JSON'){
					$this->_data[$fName] = json_decode(utf8_decode($row[$fName]));
				}else{
					$this->_data[$fName] = $row[$fName];
				}
			}
		}else{
			$this->reset();
			throw new Exception("dbTemplate::load: Invalid " . implode(', ', $this->_keys) . " value" . (count($this->_keys) > 1 ? 's' : ''));
		}

		return $row;
	}

	// assign values to a list of fields in this record, passed in as an array.
	// expects an array matching the field names in the format fieldname => value
	// does not require all fields, but will throw an exception if an invalid one is passed in.
	public function setData($data, $params = array()){
		$noAlias = in_array('noalias', $params);
		$ignoreError = in_array('noerror', $params);
		foreach($data as $field => $value){
			if($noAlias){
				if(!array_key_exists($field, $this->_fields)){
					throw new Exception('dbTemplate:' . get_class($this) . '::setData: Invalid field name "' . $field . '".');
				}
				$functionName = "set" . $this->getAlias($field);
			}else{
				$field = trim(strtolower($field));
				if(!array_key_exists($field, $this->_aliasMap)){
					throw new Exception('dbTemplate::setData: Invalid field alias "' . $field . '".');
				}
				$functionName = "set" . $field;
			}

			if(strtoupper(trim($this->_fields[$field]['type'])) == 'JSON'){
				$value = json_decode(utf8_decode($value));
			}

			try{
				$this->$functionName($value);
			}catch(Exception $e){
				// oh well.
				if(!$ignoreError){
					throw $e;
				}
			}
		}
	}

	// retrieve an array listing field aliases with their data values
	public function getData($noAlias = false){
		$rval = array();
		foreach($this->_aliasMap as $alias => $fieldName){
			$rval[$noAlias ? $fieldName : $alias] = $this->_data[$fieldName];
		}
		return $rval;
	}

	// get the record status
	public function isNewRecord(){
		return $this->_isNewRecord;
	}

	// set the record status
	public function setNewRecord($bool){
		$this->_isNewRecord = $bool ? true : false;
	}

	// get the alias used for a specified db table field.  returns the
	// actual field string if no alias is in use.
	public function getAlias($fieldName){
		if(!array_key_exists($fieldName, $this->_fields)){
			throw new Exception("Invalid field name " . $fieldName . ".");
		}
		if(array_key_exists('alias', $this->_fields[$fieldName])){
			return $this->_fields[$fieldName]['alias'];
		}else{
			return $fieldName;
		}
	}

	// returns a nested array listing off all of the field information and values for this object.
	public function getArray(){
		$rval = array();
		foreach($this->_fields as $fName => $fStruct){
			$rval[$fName] = $fStruct;
			$rval[$fName]['value'] = $this->_data[$fName];
		}
		return $rval;
	}

	// returns all records in the table in which any fields match the specified string
	public static function search($str = ''){
		$className = get_called_class();
		$query = "SELECT * FROM `" . $className::$structure['name'] . "` WHERE ";
		$conditions = array('0');
		foreach($className::$structure['fields'] as $fieldName => $fieldData){
			$conditions[] = "`" . dbTemplate::$mysqli->real_escape_string($fieldName) .
					"` LIKE '%" . dbTemplate::$mysqli->real_escape_string($str) . "%'";
		}
		$query .= implode(' OR ', $conditions);
		$result = dbTemplate::query($query);
		$rval = array();
		while($row = $result->fetch_assoc()){
			$record = new $className();
			$record->setData($row, array('noalias'));
			$rval[] = $record;
		}
		return $rval;
	}

	// returns an array of the field names in the record, aliased by default
	// unaliased if nonzero argument passed in
	public static function getFieldNames($unaliased = 0){
		$className = get_called_class();
		$rval = array();
		foreach($className::$structure['fields'] as $fieldName => $fieldData){
			$rval[] = $unaliased ? $fieldName : (array_key_exists('alias', $fieldData) ? $fieldData['alias'] : $fieldName);
		}
		return $rval;
	}

	// Returns an array of arrays containing the field values of every record in
	// the table.  Field names can be passed in either as multiple arguments or as
	// an array (or even multiple arrays) of strings.
	public static function retrieveData(){
		$className = get_called_class();
		$fields = array();
		$aliasMap = $className::getAliasMap();

		if(func_num_args() == 0){
			$fields = array_values($aliasMap);
		}else{
			$argList = func_get_args();
			for($n = 0; $n < count($argList); $n++){
				$name = $argList[$n];
				if(is_array($name)){
					// this allows us to pass in an array of fields (or several arrays even),
					// rather than a collection of individual string arguments.  Much more useful
					// when the desired fields are not hard-coded.
					$argList = array_merge($argList, $name);
					continue;
				}
				$fieldName = strtolower(trim($name));
				if(!array_key_exists($fieldName, $aliasMap)){
					throw new Exception("dbTemplate::retrieveData(<fieldnames>): invalid field name '$fieldName'");
				}
				$fields[] = $aliasMap[$fieldName];
			}

		}
		$query = "SELECT ";
		if(count($fields)){
			$query .= "`" . implode('`, `', $fields) . "` ";
		}
		$query .= "FROM `" . $className::$structure['name'] . "`";

		$q = dbTemplate::query($query);
		$rval = [];
		while($row = $q->fetch_assoc()){
			$rval[] = $row;
		}
		return $rval;
	}

	// returns an array of objects representing each record in the table.
	public static function retrieveAll(){
		$className = get_called_class();
		$q = dbTemplate::query("SELECT * FROM `" . $className::$structure['name'] . "`");
		$rval = array();
		while($row = $q->fetch_assoc()){
			$obj = new $className();

			$obj->setData($row, array('noalias', 'noerror'));
			$obj->setNewRecord(false);
			$rval[] = $obj;
		}
		return $rval;
	}

	// verify whether or not the specified field name is valid within this
	// class
	// FIXME!  This can be written use the aliasMap value, and skip the loop.
	public static function hasField($fieldName){
		$className = get_called_class();
		$rval = 0;
		if(array_key_exists($fieldName, $className::$structure['fields'])){
			$rval = 1;
		}else{
			foreach($className::$structure['fields'] as $fieldData){
				if(array_key_exists('alias', $fieldData) && $fieldData['alias'] == $fieldName){
					$rval = 1;
					break;
				}
			}
		}
		return $rval;
	}

	// --------------------------------------------------
	// retrieve record(s) by matching the specified value in the specified
	// field.  This is called by the __callStatic function defined above,
	// and is expected to be used in this fashion:
	//   $blah = user::getByLastName('Smith');
	//
	// That is identical to calling the function directly:
	//   user::getByField('lastname', 'Smith');

	public static function getByField($fieldName, $value){
		$className = get_called_class();

		// TODO: expand this to handle links as well, so I could say something like:
		// productClass::getBySupplier($supplierObject);
		// and expect a list of product objects linked to that supplier object.
		// ** maybe that's overkill though.  The above example would be equivalent to:
		// $supplierObject->getProducts();
		// or:
		// productClass::getBySupplierId($supplierObject->getId());

		$fieldName = strtolower(trim($fieldName));
		$aliasMap = $className::getAliasMap();
		if(array_key_exists($fieldName, $aliasMap)){
			$realFieldName = $aliasMap[$fieldName];
		}else if(in_array($fieldName, $aliasMap[$fieldName])){
			$realFieldName = $fieldName;
		}else{
			throw new Exception("dbTemplate::getByField('$fieldName', '$value'): Invalid field name '" . $fieldName . "'");
		}

		if($value == null){
			$query = "
				SELECT * FROM `" . $className::$structure['name'] . "`
				WHERE `" . dbTemplate::$mysqli->real_escape_string($realFieldName) . "` IS NULL
			";

		}else{
			$query = "
				SELECT * FROM `" . $className::$structure['name'] . "`
				WHERE `" . dbTemplate::$mysqli->real_escape_string($realFieldName) . "` = '"
				. $className::scrubValue($value, $realFieldName) . "'
			";
		}

		// FIXME - this really needs to be handled more elegantly.
		if(array_key_exists('orderby', $className::$structure)){
			$query .= " ORDER BY ";
			$orderSet = array();
			foreach($className::$structure['orderby'] as $orderby){
				$orderSet[] = dbTemplate::$mysqli->real_escape_string($orderby);
			}
			$query .= '`' . implode('`,`', $orderSet) . '`';
		}

		$results = dbTemplate::query($query);
		if(!$results){
			throw new Exception("dbTemplate::getByField('$fieldName', '$value'): " . dbTemplate::$mysqli->error);
		}

		// we won't put any count limit on the query, but instead
		// return an array of records of there's more than one
		if($results->num_rows == 0){
			$rval = null;
		}else if($results->num_rows == 1){
			$rval = new $className();
			$rval->setData($results->fetch_assoc(), array('noalias'));
			$rval->setNewRecord(false);
		}else if($results->num_rows > 1){
			$rval = array();
			while($row = $results->fetch_assoc()){
				$obj = new $className();
				$obj->setData($row, array('noalias'));
				$obj->setNewRecord(false);
				$rval[] = $obj;
			}
		}else{
			// shouldn't be possible, but just in case...
			throw new Exception('dbTemplate::__call::default: weird result: num_rows = ' . $results->num_rows);
		}
		return $rval;
	}

	// Import $filename as a CSV file, inserting the records into this table.
	public static function importCSV($filename){
		$keyList = array();
		$className = get_called_class();
		$aliasMap = $className::getAliasMap();
		$fin = fopen($filename, "r");
		if(!$fin){
			throw new Exception("dbTemplate::importCSV: Unable to open specified file: $filename");
		}

		$headers = fgetcsv($fin);
		for($n = 0; $n < count($headers); $n++){
			$headers[$n] = trim(strtolower($headers[$n]));
			$field = $headers[$n];
			if(!array_key_exists($field, $className::$structure['fields'])){
				if(array_key_exists($field, $aliasMap)){
					$field = $aliasMap[$field];
					$headers[$n] = $field;
				}else{
					throw new Exception("dbTemplate::importCSV: Invalid field name \"$field\"");
				}
			}
		}
		$row = fgetcsv($fin);
		$keys = $className::$structure['keys'];
		while(!feof($fin)){
			// create the record
			$record = new $className();
			for($n = 0 ; $n < count($headers); $n++){
				$record->setField($headers[$n], $row[$n]);
			}
			$record->save();

			// get the values for each key field to return in our record list
			$newResult = array();
			foreach($keys as $field){
				$newResult[$field] = $record->getField($field);
			}
			$keyList[] = $newResult;

			// grab the next CSV row
			$row = fgetcsv($fin);
		}

		return $keyList;
	}

	// Perform a raw query.
	// !!!!!!!!!  NOT ERROR CHECKED IN ANY WAY!  Only use this if you know ~exactly~ what you're doing !!!!!!!!!!
	public static function query($query){
		$rval = dbTemplate::$mysqli->query($query);
		return $rval;
	}

//@@@@@@@@@@@@@ NOT DOCUMENTED!  scrubs any string passed in @@@@@@@@@@@@@@@@
	public static function scrubString($string){
		return dbTemplate::$mysqli->real_escape_string($string);
	}

	public static function getInstance(){
		return dbTemplate::$mysqli;
	}
}
