<?php

class Database {

	private $server_name = HOST_DB;
	private $db_name     = NAME_DB;
	private $user_name   = USER_DB;
	private $password    = PASS_DB;

	private $con = null;
	private $pdo = null;
	private $stmt = null;
	private $pdo_stmt = null;
	private $query_sql = "";
	private $bind_params = [];
	private $last_result = null;
	private $last_affected = 0;

	private $is_connected = false;
	private $connection_error = "";
	private $active_password_used = "";
	private static array $tableExistsCache = [];

	public function __construct($customDbName = null){
		if ($customDbName !== null) {
			$this->db_name = $customDbName;
		}
		$this->init_connection();
	}

	public function init_connection(){
		$this->is_connected = false;
		$this->connection_error = "";
		$this->pdo = null;

		$candidates = [];
		if (!empty($_SESSION['_va_db_pwd_ok']) && isset($_SESSION['_va_db_pwd'])) {
			$candidates[] = (string)$_SESSION['_va_db_pwd'];
		}
		$candidates[] = $this->password;
		$candidates[] = "";
		$candidates[] = "12345";
		$candidates[] = "root";
		$passwords_to_try = array_values(array_unique($candidates));
		$host = ($this->server_name === 'localhost') ? '127.0.0.1' : $this->server_name;
		$lastError = "";

		foreach ($passwords_to_try as $pwd) {
			try {
				$conn = @new mysqli($host, $this->user_name, $pwd, $this->db_name);
				if (!$conn->connect_error) {
					$this->con = $conn;
					$this->active_password_used = $pwd;
					$this->is_connected = true;
					$this->connection_error = "";
					if (session_status() === PHP_SESSION_ACTIVE) {
						$_SESSION['_va_db_pwd_ok'] = true;
						$_SESSION['_va_db_pwd'] = $pwd;
					}
					break;
				}
				$lastError = $conn->connect_error;
				$errNo = (int)$conn->connect_errno;
				if ($errNo === 1045 || $errNo === 1044) {
					continue;
				}

				$connServer = @new mysqli($host, $this->user_name, $pwd);
				if (!$connServer->connect_error) {
					$dbCheck = @$connServer->select_db($this->db_name);
					if (!$dbCheck) {
						$safeDbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$this->db_name);
						$createDb = $safeDbName !== '' ? @$connServer->query("CREATE DATABASE IF NOT EXISTS `" . $safeDbName . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci") : false;
						if ($createDb && @$connServer->select_db($safeDbName)) {
							$this->con = $connServer;
							$this->active_password_used = $pwd;
							$this->is_connected = true;
							$this->connection_error = "";
							if (session_status() === PHP_SESSION_ACTIVE) {
								$_SESSION['_va_db_pwd_ok'] = true;
								$_SESSION['_va_db_pwd'] = $pwd;
							}
							break;
						} else {
							$lastError = "Database '" . $this->db_name . "' cannot be initialized.";
						}
					} else {
						$this->con = $connServer;
						$this->active_password_used = $pwd;
						$this->is_connected = true;
						$this->connection_error = "";
						if (session_status() === PHP_SESSION_ACTIVE) {
							$_SESSION['_va_db_pwd_ok'] = true;
							$_SESSION['_va_db_pwd'] = $pwd;
						}
						break;
					}
				} else {
					$lastError = $connServer->connect_error;
				}
			} catch (Throwable $e) {
				$lastError = $e->getMessage();
			}
		}

		if ($this->is_connected && $this->con) {
			@$this->con->set_charset("utf8mb4");
		} else {
			$this->connection_error = !empty($lastError) ? $lastError : "Unable to connect to MySQL at " . $this->server_name;
		}
	}

	public function ensure_connected(){
		try {
			if (!$this->con || !@$this->con->ping()) {
				$this->init_connection();
			}
		} catch (Throwable $e) {
			$this->init_connection();
		}
	}

	public function isConnected(){
		return $this->is_connected && ($this->con instanceof mysqli);
	}

	public function getConnectionError(){
		return $this->connection_error;
	}

	public function getDatabaseName(){
		return $this->db_name;
	}

	public function getConnection(){
		$this->ensure_connected();
		return $this->con;
	}

	public function getPdo(){
		if ($this->pdo instanceof PDO) {
			return $this->pdo;
		}
		if (!$this->isConnected()) {
			$this->init_connection();
		}
		$pwd = $this->active_password_used !== '' ? $this->active_password_used : $this->password;
		$host = ($this->server_name === 'localhost') ? '127.0.0.1' : $this->server_name;
		try {
			$dsn = "mysql:host=" . $host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
			$this->pdo = new PDO($dsn, $this->user_name, $pwd, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false
			]);
			return $this->pdo;
		} catch (PDOException $e) {
			return null;
		}
	}

	public function clearTableCache(){
		self::$tableExistsCache = [];
	}

	public function getTables(){
		if (!$this->isConnected()) return [];
		$tables = [];
		try {
			$res = $this->con->query("SHOW TABLES");
			if ($res) {
				while ($row = $res->fetch_row()) {
					$tables[] = $row[0];
					self::$tableExistsCache[$this->db_name . '.' . $row[0]] = true;
				}
			}
		} catch (Throwable $e) {}
		return $tables;
	}

	public function hasTable($tableName){
		if (!$this->isConnected()) return false;
		$cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
		if ($cleanName === '') return false;
		$cacheKey = $this->db_name . '.' . $cleanName;
		if (array_key_exists($cacheKey, self::$tableExistsCache)) {
			return self::$tableExistsCache[$cacheKey];
		}
		$pdo = $this->getPdo();
		if ($pdo) {
			try {
				$st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1");
				$st->execute([$this->db_name, $cleanName]);
				$exists = (bool)$st->fetchColumn();
				self::$tableExistsCache[$cacheKey] = $exists;
				return $exists;
			} catch (Throwable $e) {}
		}
		$safe = $this->con->real_escape_string($cleanName);
		$res = @$this->con->query("SHOW TABLES LIKE '" . $safe . "'");
		$exists = ($res instanceof mysqli_result) && ($res->num_rows > 0);
		self::$tableExistsCache[$cacheKey] = $exists;
		return $exists;
	}

	public function getTableRowCount($tableName){
		if (!$this->isConnected() || !$this->hasTable($tableName)) return 0;
		try {
			$safeName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
			if ($safeName === '') return 0;
			$res = $this->con->query("SELECT COUNT(*) AS total FROM `" . $safeName . "`");
			if ($res && ($row = $res->fetch_assoc())) {
				return (int)$row['total'];
			}
		} catch (Throwable $e) {}
		return 0;
	}

	public function getDiagnostics(){
		$tables = $this->getTables();
		$requiredTables = [
			'tbl_users',
			'tbl_roles',
			'tbl_permissions',
			'tbl_role_permissions',
			'tbl_user_roles',
			'tbl_user_role_history',
			'tbl_students',
			'tbl_study_programs',
			'tbl_agents',
			'tbl_agent_role_access',
			'tbl_agent_tools',
			'tbl_agent_knowledge',
			'tbl_agent_prompts',
			'tbl_agent_versions',
			'tbl_agent_configurations',
			'tbl_agent_tasks',
			'tbl_agent_task_logs',
			'tbl_agent_execution_history',
			'tbl_agent_collaborations',
			'tbl_agent_events',
			'tbl_conversations',
			'tbl_messages',
			'tbl_knowledge_bases',
			'tbl_knowledge_documents',
			'tbl_knowledge_chunks',
			'tbl_document_access_permissions',
			'tbl_audit_logs',
			'tbl_api_usage_logs',
			'tbl_system_settings'
		];
		$missingTables = array_diff($requiredTables, $tables);
		$serverVersion = ($this->con) ? @$this->con->server_info : null;

		return [
			'is_connected' => $this->isConnected(),
			'host' => $this->server_name,
			'database' => $this->db_name,
			'user' => $this->user_name,
			'error_message' => $this->connection_error,
			'server_version' => $serverVersion,
			'tables' => $tables,
			'total_tables' => count($tables),
			'required_tables' => $requiredTables,
			'missing_tables' => array_values($missingTables),
			'is_schema_ready' => empty($missingTables) && count($tables) > 0
		];
	}

	public function importSqlFile($sqlFilePath){
		if (!file_exists($sqlFilePath)) {
			return ['success' => false, 'message' => "SQL file not found: " . htmlspecialchars($sqlFilePath)];
		}

		$sqlContent = file_get_contents($sqlFilePath);
		if (empty(trim($sqlContent))) {
			return ['success' => false, 'message' => "SQL file is empty."];
		}

		$pwd = $this->active_password_used !== '' ? $this->active_password_used : $this->password;
		$passwords = array_unique([$pwd, "", "12345", "root"]);
		$host = ($this->server_name === 'localhost') ? '127.0.0.1' : $this->server_name;
		$conn = null;

		foreach ($passwords as $p) {
			try {
				$c = @new mysqli($host, $this->user_name, $p);
				if (!$c->connect_error) {
					$conn = $c;
					break;
				}
			} catch (Throwable $e) {}
		}

		if (!$conn) {
			return ['success' => false, 'message' => "Unable to connect to MySQL server for import."];
		}

		$conn->set_charset("utf8mb4");
		$this->clearTableCache();

		if ($conn->multi_query($sqlContent)) {
			$statementCount = 0;
			$sqlErr = '';
			do {
				if ($res = $conn->store_result()) {
					$res->free();
				}
				if ($conn->errno) {
					$sqlErr = $conn->error;
					break;
				}
				$statementCount++;
			} while ($conn->more_results() && $conn->next_result());

			if ($conn->errno && $sqlErr === '') {
				$sqlErr = $conn->error;
			}
			@$conn->close();

			if ($sqlErr !== '') {
				return ['success' => false, 'message' => "SQL execution error at statement #{$statementCount}: " . $sqlErr];
			}

			$this->init_connection();
			return [
				'success' => true,
				'statements' => $statementCount,
				'message' => "Database imported successfully ({$statementCount} statements executed)."
			];
		} else {
			$err = $conn->error;
			@$conn->close();
			return ['success' => false, 'message' => "SQL execution error: " . $err];
		}
	}

	public function query($sql){
		$this->query_sql = $sql;
		$this->bind_params = [];
		$this->stmt = null;
		$this->pdo_stmt = null;

		if (preg_match('/:[a-zA-Z_][a-zA-Z0-9_]*/', $sql)) {
			return $this;
		}

		$this->ensure_connected();

		if ($this->con) {
			$this->last_result = $this->con->query($sql);
			$this->last_affected = $this->con->affected_rows;
			return $this->last_result;
		}
		return false;
	}

	public function prepare($sql){
		$this->query_sql = $sql;
		$this->bind_params = [];
		$this->pdo_stmt = null;
		return $this;
	}

	public function bind($param, $value, $type = null){
		$paramName = ltrim((string)$param, ':');
		$this->bind_params[$paramName] = [$value, $type];
		return $this;
	}

	private function compileNamedSqlToPositional(string $sql, array $boundParams): array {
		$orderedValues = [];
		$len = strlen($sql);
		$out = '';
		$inSingle = false;
		$inDouble = false;
		$inBacktick = false;

		for ($i = 0; $i < $len; $i++) {
			$ch = $sql[$i];
			$prev = $i > 0 ? $sql[$i - 1] : '';

			if ($ch === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
				$inSingle = !$inSingle;
				$out .= $ch;
				continue;
			}
			if ($ch === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
				$inDouble = !$inDouble;
				$out .= $ch;
				continue;
			}
			if ($ch === '`' && !$inSingle && !$inDouble) {
				$inBacktick = !$inBacktick;
				$out .= $ch;
				continue;
			}

			if (!$inSingle && !$inDouble && !$inBacktick && $ch === ':') {
				if (preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)\b/', substr($sql, $i), $m)) {
					$key = $m[1];
					if (array_key_exists($key, $boundParams)) {
						$out .= '?';
						$orderedValues[] = $boundParams[$key];
						$i += strlen($m[0]) - 1;
						continue;
					}
				}
			}
			$out .= $ch;
		}

		return [$out, $orderedValues];
	}

	public function execute(){
		$pdo = $this->getPdo();
		if ($pdo) {
			try {
				[$compiledSql, $orderedValues] = $this->compileNamedSqlToPositional($this->query_sql, $this->bind_params);
				$stmt = $pdo->prepare($compiledSql);
				foreach ($orderedValues as $idx => $entry) {
					[$val, $explicitType] = $entry;
					$pos = $idx + 1;
					if ($val === null) {
						$stmt->bindValue($pos, null, PDO::PARAM_NULL);
					} elseif ($explicitType === PDO::PARAM_INT || (is_int($val) && $explicitType === null)) {
						$stmt->bindValue($pos, (int)$val, PDO::PARAM_INT);
					} elseif ($explicitType === PDO::PARAM_BOOL || is_bool($val)) {
						$stmt->bindValue($pos, $val ? 1 : 0, PDO::PARAM_INT);
					} else {
						$stmt->bindValue($pos, (string)$val, PDO::PARAM_STR);
					}
				}
				$ok = $stmt->execute();
				$this->pdo_stmt = $stmt;
				$this->last_affected = $stmt->rowCount();
				$this->last_result = null;
				return $ok;
			} catch (PDOException $e) {
				error_log('Database PDO error: ' . $e->getMessage());
				$this->pdo_stmt = null;
				$this->last_affected = 0;
				return false;
			}
		}
		return false;
	}

	public function runPrepared(string $sql, array $params = []): ?PDOStatement {
		$pdo = $this->getPdo();
		if (!$pdo) return null;
		try {
			$stmt = $pdo->prepare($sql);
			foreach (array_values($params) as $idx => $val) {
				$pos = $idx + 1;
				if ($val === null) {
					$stmt->bindValue($pos, null, PDO::PARAM_NULL);
				} elseif (is_int($val)) {
					$stmt->bindValue($pos, $val, PDO::PARAM_INT);
				} elseif (is_bool($val)) {
					$stmt->bindValue($pos, $val ? 1 : 0, PDO::PARAM_INT);
				} else {
					$stmt->bindValue($pos, (string)$val, PDO::PARAM_STR);
				}
			}
			$stmt->execute();
			$this->pdo_stmt = $stmt;
			$this->last_affected = $stmt->rowCount();
			return $stmt;
		} catch (PDOException $e) {
			error_log('Database runPrepared error: ' . $e->getMessage());
			return null;
		}
	}

	public function resultSet(){
		if (!$this->execute()) {
			return [];
		}
		if ($this->pdo_stmt instanceof PDOStatement) {
			return $this->pdo_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
		}
		if ($this->last_result instanceof mysqli_result) {
			$rows = [];
			while ($row = $this->last_result->fetch_assoc()) {
				$rows[] = $row;
			}
			return $rows;
		}
		return [];
	}

	public function single(){
		if (!$this->execute()) {
			return null;
		}
		if ($this->pdo_stmt instanceof PDOStatement) {
			$row = $this->pdo_stmt->fetch(PDO::FETCH_ASSOC);
			return $row !== false ? $row : null;
		}
		if ($this->last_result instanceof mysqli_result) {
			$row = $this->last_result->fetch_assoc();
			return $row ?: null;
		}
		return null;
	}

	public function rowCount(){
		if ($this->pdo_stmt instanceof PDOStatement) {
			return $this->pdo_stmt->rowCount();
		}
		if ($this->last_result instanceof mysqli_result) {
			return $this->last_result->num_rows;
		}
		return $this->last_affected;
	}

	public function lastInsertId(){
		if ($this->pdo instanceof PDO) {
			$id = (int)$this->pdo->lastInsertId();
			if ($id > 0) return $id;
		}
		return $this->con ? (int)$this->con->insert_id : 0;
	}

	public function db_close(){
		$this->pdo_stmt = null;
		$this->pdo = null;
		if ($this->con) {
			@$this->con->close();
			$this->con = null;
		}
		$this->is_connected = false;
	}

	public function my_real_escape_string($string){
		$this->ensure_connected();
		if ($this->con && $string !== null) {
			return $this->con->real_escape_string($string);
		}
		return addslashes((string)$string);
	}

	public function beginTransaction(){
		$pdo = $this->getPdo();
		if ($pdo && !$pdo->inTransaction()) {
			$pdo->beginTransaction();
			return true;
		}
		if ($this->con) {
			$this->con->begin_transaction();
			return true;
		}
		return false;
	}

	public function commit(){
		$pdo = $this->getPdo();
		if ($pdo && $pdo->inTransaction()) {
			$pdo->commit();
			return true;
		}
		if ($this->con) {
			$this->con->commit();
			return true;
		}
		return false;
	}

	public function rollBack(){
		$pdo = $this->getPdo();
		if ($pdo && $pdo->inTransaction()) {
			$pdo->rollBack();
			return true;
		}
		if ($this->con) {
			$this->con->rollback();
			return true;
		}
		return false;
	}
}
