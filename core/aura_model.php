<?php
    class aura_model extends basics
	{
		private $currentConnections;
		
		//private function __construct() {}

		/**
		 * Creates selection functions
		 * @return Object
		 */
		function __call($method, $params)
		{
			if (!$this->table_name)
				return false;
			
			if (strpos($method, 'getBy') !== false OR $method == 'getAll') {
				return $this->getElements($method, $params);
			}
			elseif ($method == 'set') {
				return $this->setElements($method, $params);
			}
		}
		
		/**
		 * Connects to the database
		 * @return Object
		 */
		private function connection()
		{
			if (array_search($this->driver, PDO::getAvailableDrivers()) === FALSE) {
				Helper::log('database', "Driver '{$this->driver}' not available");
				exit;
			}
			
			try {
				$pdo = new PDO("{$this->driver}:host={$this->host};dbname={$this->database};charset=UTF-8", "{$this->login}", "{$this->password}");
				$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
				
				// Check if database exists
				$sql = $pdo->prepare("SHOW TABLES");
				$sql->execute();
				
				return $pdo;
			}
			catch ( PDOException $Exception ) {
				Helper::log('database', $Exception->getMessage());
				exit;
			}
		}
		
		/**
		 * Select the database
		 * @return Object
		 */
		public function database()
		{
			$config = get_class_vars('APP_DATABASE');
			
			if (!isset($config['set']) OR !$config['set']) {
				Helper::log('database', 'Error in database.php. The variable \'set\' is null or does not exist');
				exit;
			}
			
			if (!isset($config[$config['set']])) {
				Helper::log('database', 'Error in database.php. Invalid database information (set: ' . $config['set'] . ')');
				exit;
			}
			
			$database = $config[$config['set']];
			$ref = $config['set'];
			
			$this->driver = $database['driver'];
			$this->host = $database['host'];
			$this->login = $database['login'];
			$this->password = $database['password'];
			$this->database = $database['database'];
			$this->prefix = $database['prefix'];

			if (!is_array($this->currentConnections))
				$this->currentConnections = Array();

			if (!array_key_exists($ref, $this->currentConnections)) {
				$this->currentConnections = Array();
				$this->currentConnections[$ref] = $this->Connection();
			}
			
			if (AURA_DEFAULT_MYSQL_CHARSET) {
				$this->currentConnections[$ref]->query('SET character_set_client=' . AURA_DEFAULT_MYSQL_CHARSET);
				$this->currentConnections[$ref]->query('SET character_set_results=' . AURA_DEFAULT_MYSQL_CHARSET);
			}

			return $this->currentConnections[$ref];
		}
		
		/**
		 * Get elements from database
		 * @return Object
		 */
		private function getElements($method, $params)
		{
			$where = isset($this->return_data) ? "WHERE {$this->return_data} AND " : null;
			$limit = null;
			$pagination = null;
			$orderby = null;
			
			if ($params) {
				// Pagination
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('pagination', $fields)) {
						$pagination['page'] = $fields['pagination']['page'];
						$pagination['register_per_page'] = $fields['pagination']['registers'];
						
						$fields['pagination']['page']--;
						$init = $fields['pagination']['page'] * $fields['pagination']['registers'];
						
						if ($fields['pagination']['registers'] != 'all') {
							$limit = "LIMIT " . $init . "," . $fields['pagination']['registers'];
						}
						
						unset($params[$key]['pagination']);
					}
				}
				
				// Order by
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('order', $fields)) {
						$orderby = "ORDER BY " . $fields['order'];	
						unset($params[$key]['order']);
					}
				}
				
				// Limit
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('limit', $fields)) {
						$limit = "LIMIT " . $fields['limit'];	
						unset($params[$key]['limit']);
					}
				}

				// Where clause
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('where', $fields)) {
						if ($fields['where']) {
							if (!$where)
								$where .= 'WHERE ' . $fields['where'];
						}
						
						unset($params[$key]['where']);
					}
				}
				
				// Filter generated by helper
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('filter', $fields)) {
						if ($fields['filter']) {
							if (!$where)
								$where .= 'WHERE ';
							
							if (empty($field))
								break;
							
							foreach ($fields as $key => $filter) {
								$where .= $key . " " . $filter['condition'] . " ";
								
								if (strtolower($filter['condition']) == 'like')
									$where .= is_numeric($filter['value']) ? '%' . $filter['value'] . '%' : "'%{$filter['value']}%'";
								else
									$where .= is_numeric($filter['value']) ? $filter['value'] : "'{$filter['value']}'";
								
								$where .= " AND ";
							}
						}
						
						unset($params[$key]['filter']);
					}
				}

				foreach ($params as $key => $fields) {
					if ($fields && !empty($fields)) {
						$by_value = $fields;
					}
				}
			}
			
			if (strpos($method, 'getBy') !== false) {
				$by = explode('getBy', $method);
				$by = isset($by[1]) ? strtolower(trim($by[1])) : null;
				
				if ($by && isset($by_value) && $by_value) {
					$by_value = is_string($by_value) ? "'$by_value'" : $by_value;
					$where = $where ? $where . " AND $by = $by_value" : "WHERE $by = $by_value";
					
					$where .= " AND ";
				}
			}
			
			try {
				$database = $this->database();
				$where = substr($where, 0, -4);
				
				// Pagination
				if ($pagination) {
					$sql = $database->prepare("SELECT COUNT(*) as N FROM {$this->table_name} $where");
					$sql->execute();
					
					$pagination['url'] = $_SERVER['REQUEST_URI'];
					$pagination['registers'] = $sql->fetch( PDO::FETCH_OBJ )->N;
					$pagination['pages'] = is_numeric($pagination['register_per_page']) ? ceil($pagination['registers']/$pagination['register_per_page']) : 1;
					
					$url = explode("/", $_SERVER['REQUEST_URI']);
					
					foreach ($url as $i => $block) {
						if ($block == 'pagina') {
							unset($url[$i]);
							unset($url[$i+1]);
						}
					}
					$url = join('/', $url);
					$url = substr($url, -1) == '/' ? substr($url, 0, -1) : $url;
					
					$pagination['url'] = $url;
				}

				$pagination = $pagination ? (object)$pagination : $pagination;
				
				// Get registers
				$sql = $database->prepare("SELECT * FROM {$this->table_name} $where $orderby $limit");
				$sql->execute();

				if (isset($by) && $this->table_key == $by)
					$result = $sql->fetch( PDO::FETCH_OBJ );
				else
					$result = $sql->fetchAll( PDO::FETCH_OBJ );

				// Check relationships
				$this->check_relationships($result);

				// Return
				$return = array('data' => $result, 'pagination' => $pagination);
				
				return $return;
			}
			catch( exception $e ) {
				Helper::log('database', $e->getMessage());
				return false;
			}
			
			return false;
		}
		
		/**
		 * Check relationships based
		 * @return Change object
		 */
		private function check_relationships($result)
		{
			if ($result) {
				if (is_array($result)) {
					foreach ($result as $item) {
						$vars = get_object_vars($item);

						foreach ($vars as $field => $value) {
							if (strpos($field, '_id') !== false)
								$this->get_relationships($field, $item);
						}
					}	
				}
				else {
					$vars = get_object_vars($result);

					foreach ($vars as $field => $value) {
						if (strpos($field, '_id') !== false)
							$this->get_relationships($field, $result);
					}
				}
			}
		}

		/**
		 * Get relationships based in field name
		 * @return Change object
		 */
		private function get_relationships($field, $item)
		{
			$database = $this->database();

			// Get table name
			$table = reset(explode('_id', $field));

			// Get register
			$sql = $database->prepare("SELECT * FROM $table WHERE id = {$item->$field}");
			$sql->execute();
			$table_data = $sql->fetch( PDO::FETCH_OBJ );

			// Check others relationships
			$this->check_relationships($table_data);

			// Create field name to array
			$table = $table . '_data';

			if ($table_data) {
				unset($item->$field);
				$item->$table = $table_data;
			}
		}

		/**
		 * Edit/Save elements on database
		 * @return Object
		 */
		private function setElements($method, $params)
		{
			$where = null;
			$update = false;
			$data_update = null;
			$data_insert['fields'] = null;
			$data_insert['values'] = null;

			if ($params && !empty($params)) {
				// Where clause
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('where', $fields)) {
						if ($fields['where']) {
							if (!$where)
								$where .= 'WHERE ';
							
							foreach ($fields['where'] as $itens)								
								$where .= $itens . ' AND ';
						}
						
						unset($params[$key]['where']);
					}
				}
				
				// Data to insert table
				foreach ($params as $key => $fields) {
					if (is_array($fields) && array_key_exists('data', $fields)) {
						if ($fields['data']) {
							foreach ($fields['data'] as $field => $value) {
								$k = false;

								foreach (explode(',', $this->table_key) as $key) {
									if ($field == $key) {
										if (!$where)
											$where .= 'WHERE ';

										$where .= "$key = $value AND ";
										$update = $k = true;
									}
								}
								
								if (!$k) {
									$data_update .= "$field = '$value', ";
									$data_insert['fields'] .= "$field, ";
									$data_insert['values'] .= "'$value', ";
								}
							}
						}
						
						unset($params[$key]['data']);
					}
				}
			}
			else {
				Helper::log('database', "Data not found in method set ({$this->table_name}->set())");
				return false;
			}

			try {
				$database = $this->database();

				$where = substr($where, 0, -4);
				$data_update = substr($data_update, 0, -2);
				$data_insert['fields'] = substr($data_insert['fields'], 0, -2);
				$data_insert['values'] = substr($data_insert['values'], 0, -2);

				if ($update)
					$sql = $database->prepare("UPDATE {$this->table_name} SET $data_update $where");
				else
					$sql = $database->prepare("INSERT INTO {$this->table_name} ({$data_insert['fields']}) VALUES ({$data_insert['values']}) $where");

				$result = $sql->execute();
				$result = $result ? (int)$database->lastInsertId() : $result;

				return $result;
			}
			catch ( Exception $e ) {
				Helper::log('database', $e->getMessage());
				return false;
			}
		}
		
		/**
		 * Close connection
		 * @param object $ref [optional] : Set name
		 * @return Bool
		 */
		protected function close($ref = null)
		{
			if ($ref) {
				Helper::log('database', "Connection finished ($ref)");
				$this->currentConnections[$ref] = null;
			}
			else {
				Helper::log('database', 'Connection finished');
				$this->currentConnections = null;
			}
			
			return true;
		}
	}
?>