<?php
// This class is used for simplifying the handling of database records.  Any
// record can be retreived as an object, its values manipulated, etc.  Data is
// scrubbed in the dbTemplate class to prevent SQL injections when it gets saved.
// tl;dr: It's a simple ORM of sorts
/*
TODO::::
 - ad an error checking on construct that throws an exception if an alias is
   identical to another field name.

 - ensure that all field flags are case-insensitive

 - move boolean fields into an array called flags?

 - change the maximum link field recursion depth to a defiend constant somewhere, rather than a local hard-coded one.

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
	protected $_mysqli;
	static protected $mysqli;

	function __construct(){
		$this->_initialize();
		$this->_postInitialize();

		$this->reset();
		$this->_mysqli = static::$mysqli;

		$numArgs = func_num_args();
		$numExpectedArgs = count($this->_keys);
		if($numArgs == $numExpectedArgs){
			$this->load(func_get_args());
		}else if($numArgs != 0){
			$errMsg = get_class($this) . " construct expects $numExpectedArgs parameter" . ($numExpectedArgs == 1 ? '' : 's');
			$errMsg .= " (" . implode(', ', $this->_keys);
			$errMsg .= "), received $numArgs instead";
			throw new Exception($errMsg);
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
			'links' => '_links'
		);
		foreach($definitions as $name => $internalName){
			if(array_key_exists($name, $className::$structure)){
				$this->$internalName = $className::$structure[$name];
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
		}
		$this->_aliasMap = $this->getAliasMap();
	}

	// reset this record to a blank one
	public function reset(){
		foreach($this->_fields as $fName => $fData){
			$this->_data[$fName] = $fData['default'];
		}

		// scrub table links and define the array if it doesn't exists
		$newLinks = array();
		if(is_array($this->_links)){
			foreach($this->_links as $key => $val){
				$newLinks[strtolower(trim($key))] = $val;
			}
		}
		$this->_links = $newLinks;
		$this->_isNewRecord = true;
	}

	// delete this record from the database and reset the object
	public function delete(){

		if(method_exists($this, '_predelete')){
			$this->_predelete();
		}

		if(!$this->_isNewRecord){
			$query = "DELETE FROM `" . $this->_tableName . "` WHERE ";
			$queryParts = array();
			foreach($this->_keys as $keyField){
				$queryParts[] .= "`$keyField` = '" . $this->_data[$keyField] . "'";
			}
			$query .= implode(' AND ', $queryParts);
			if(!$this->_mysqli->query($query)){
				throw new Exception("Unable to delete record: {$this->_mysqli->error}\nFailed query: $query");
			}
		}
		$this->reset();
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
				
				$aliasMap[strtolower($fData['alias'])] = $fName;
			}else{
				$aliasMap[strtolower($fName)] = $fName;
			}
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
				$rval = date('Y-m-d H:i:s', strtotime($value));
				break;
			case 'DECIMAL':
				if(array_key_exists('rounding', $fieldDef)){
					$digits = intval($fieldDef['rounding']);
					$rval = number_format(floatval($value), $digits);
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
			case 'VARCHAR': case 'TEXT': case 'DATE': case 'TIME': case 'DATETIME':
				$rval = dbTemplate::$mysqli->real_escape_string($value);
				break;
			case 'ENUM':
				if(in_array($value, $fieldDef['values'])){
					$rval = dbTemplate::$mysqli->real_escape_string($value);
				}
				break;
			default:
				$rval = dbTemplate::$mysqli->real_escape_string($value);
		}

		if(array_key_exists('unsigned', $fieldDef)){
			if(in_array($fieldType, array('INT', 'INTEGER', 'DECIMAL', 'FLOAT'))){
				$rval = abs($rval);
			}
		}

		return $rval;
	}

	// handle dynamic static functions like <class>::getBy<field>(<value>);
	public static function __callStatic($funcName, $args){
		$rval = null;
		if(strtolower(substr($funcName, 0, 5)) == 'getby'){
			if(count($args) != 1){
				throw new Exception("dbTemplate::__callStatic<$funcName>: invalid argument count (" . count($args) . ")");
			}

			if(is_array($args[0])){
				$idList = $args[0];
			}else{
				$idList = array($args[0]);
			}

			$results = array();
			foreach($idList as $id){
				$className = get_called_class();
				$val = $className::getByField(substr($funcName, 5), $id);
				if($val != null){
					$results[] = $val;
				}
			}
			if(count($results) == 1){
				$rval = $results[0];
			}else if(count($results) > 1){
				$rval = $results;
			}
		}else{
			// Could also add a get<Field> and set<Field> function that updates one particualr
			// field on all records.  May not be that useful though.

			throw new Exception("dbTemplate::$funcName is not defined.");
		}
		return $rval;
	}

	// find the record(s) that this one links to based on the links 
	// $definition should be the array that defines the link.  This is passed in to allow for recursion
	public function linkedRecords($definition, $maxRecursion = 10){
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
		$results = $this->_mysqli->query($query);

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
		}else{
			$prefix = substr($func, 0, 3);
			if($prefix == 'get'){
				$name = substr($func, 3);
				return $this->getField($name);
			
			}else if($prefix == 'set'){
				$name = substr($func, 3);
				$thisField = null;
				if(array_key_exists($name, $this->_aliasMap)){
					$thisField = $this->_aliasMap[$name];
				}else if(array_key_exists($name, $this->_fields)){
					$thisField = $name;
				}
				if($thisField != null){
					if(count($params) != 1){
						throw new Exception("function " . get_class($this) . "::set$name expects a single value parameter");
					}

					// call the custom validator if defined.  It's expected to throw an exception if the field value is invalid
					if(array_key_exists('validator', $this->_fields[$thisField])){
						$this->{$this->_fields[$thisField]['validator']}($params[0]);
					}
					// if a custom scrubber is defined, it should return a clean version of data passed in
					if(array_key_exists('scrubber', $this->_fields[$thisField])){
						$params[0] = $this->{$this->_fields[$thisField]['scrubber']}($params[0]);
					}


					// if a custom function for handling a set on this variable is defined, then call it
					if(array_key_exists('sethandler', $this->_fields[$thisField])){
						// fixme... This will not handle the case where "sethandler" points to
						// an undefined function.  It may give infinite recursion as a result.
						return $this->{$this->_fields[$thisField]['sethandler']}($params[0]);
					}else{
						// validate ENUMS
						if($this->_fields[$thisField]['type'] == 'enum'){
							if(!in_array($params[0], $this->_fields[$thisField]['values'])){
								throw new Exception("field $name is an ENUM and expects one of the following values: " . implode(', ', $this->_fields[$thisField]['values']));
							}
						}

						// validate integer fields
						if(in_array($this->_fields[$thisField]['type'], array('INT', 'INTEGER'))){
							if(!preg_match('/^[0-9+-]*$/', $params[0])){
								throw new Exception("field $name expects an integer value");
							}
						}

						// validate VARCHAR with a defined length
						if(array_key_exists('maxlength', $this->_fields[$thisField])){
							if(strlen($params[0]) > $this->_fields[$thisField]['maxlength']){
								throw new Exception("value '{$params[0]}' exceeds maximum field length of " . $this->_fields[$thisField]['maxlength']);
							}
						}
						// handle decimal restrictions if applicable
						if($this->_fields[$thisField]['type'] == 'DECIMAL'){
							if(array_key_exists('decimalformat', $this->_fields[$thisField])){
								if(strlen(intval($params[0])) > $this->_fields[$thisField]['decimalformat']['left']){
									throw new Exception("dbTemplate::$functionName: value exceeds left digit limit of " . $this->_fields[$thisField]['decimalformat']['left']);
								}
								$params[0] = round(floatval($params[0]), $this->_fields[$thisField]['decimalformat']['right']);
							}
						}

						// handle absolute values
						if(array_key_exists('unsigned', $this->_fields[$thisField])){
							if(is_numeric($params[0])){
								$params[0] = abs($params[0]);
							}
						}

						// hey!  If we made it this far, then the value seems valid and we can store it
						$this->_data[$thisField] = $params[0];
						return $params[0];
					}
				}else if(array_key_exists($name, $this->_links)){
					if(count($params) != 1){
						throw new Exception("dbTemplate::$functionName: expecting one parameter of dbTemplate type.  Received " . count($params) . " objects");
					}

					$objType = gettype($params[0]);
					if($objType != 'object'){
						throw new Exception("dbTemplate::$functionName: expecting record of class '" . $this->_links[$name]['class'] . "', instead received one of type $objType");
					}
					$objClass = get_class($params[0]);
					if($objClass != $this->_links[$name]['class']){
						throw new Exception("dbTemplate::$functionName: expecting record of class '" . $this->_links[$name]['class'] . "', instead received one of type $objClass");
					}

					// no exception?  Ok, we can assign the necessary link fields.
					foreach($this->_links[$name]['linkfields'] as $myField => $theirField){
						$this->_data[$myField] = $params[0]->{'get' . $theirField}();
					}
				}else{
					throw new Exception(get_class($this) . "::set$name: Invalid field name \"$name\"");
				}
			}else{
				throw new Exception("dbTemplate::__call: call to non-existant member function: " . $functionName);
			}
		}
	}

	// make the object's string value a json object string
	function __tostring(){
		return json_encode($this->getData(true));
	}

	// save the record, and update accordingly if it's a newly created one.
	public function save(){
		if($this->_isNewRecord){
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
					}else{
						$valueList[] = "'$val'";
					}
				}
			}
			$query .= " (`" . implode('`, `', $fieldList) . "`)";
			$query .= " VALUES (" . implode(", ", $valueList) . ")";
			if(!$this->_mysqli->query($query)){
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
		}else{
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
			if(!$this->_mysqli->query($query)){
				throw new Exception("Unable to update record: " . $this->_mysqli->error);
			}
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
		$result = $this->_mysqli->query($query);
		$row = $result->fetch_assoc();
		if($row){
			$this->_isNewRecord = false;
			foreach($this->_fields as $fName => $fData){
				$this->_data[$fName] = $row[$fName];
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
					throw new Exception('dbTemplate::setData: Invalid field name "' . $field . '".');
				}
				$functionName = "set" . $this->getAlias($field);
			}else{
				$field = trim(strtolower($field));
				if(!array_key_exists($field, $this->_aliasMap)){
					throw new Exception('dbTemplate::setData: Invalid field alias "' . $field . '".');
				}
				$functionName = "set" . $field;
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
		$result = dbTemplate::$mysqli->query($query);
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

	// returns an array of objects representing each record in the table.
	public static function retrieveAll(){
		$className = get_called_class();
		$q = dbTemplate::$mysqli->query("SELECT * FROM `" . $className::$structure['name'] . "`");
		$rval = [];
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

		$query = "
			SELECT * FROM `" . $className::$structure['name'] . "`
			WHERE `" . dbTemplate::$mysqli->real_escape_string($realFieldName) . "` = '"
			. $className::scrubValue($value, $realFieldName) . "'
		";

		// FIXME - this really needs to be handled more elegantly.
		if(array_key_exists('orderby', $className::$structure)){
			$query .= " ORDER BY ";
			$orderSet = array();
			foreach($className::$structure['orderby'] as $orderby){
				$orderSet[] = dbTemplate::$mysqli->real_escape_string($orderby);
			}
			$query .= '`' . implode('`,`', $orderSet) . '`';
		}

		$results = dbTemplate::$mysqli->query($query);
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
}
