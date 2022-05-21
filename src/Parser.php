<?php namespace Model\DbParser;

use Model\Cache\Cache;

class Parser
{
	/** @var string[] */
	private array $tablesList;
	/** @var Table[] */
	private array $tablesCache = [];

	/**
	 * @param \PDO $db
	 */
	public function __construct(private \PDO $db)
	{
	}

	/**
	 * Db getter
	 *
	 * @return \PDO
	 */
	public function getDb(): \PDO
	{
		return $this->db;
	}

	/**
	 * @param string $name
	 * @return Table
	 */
	public function getTable(string $name): Table
	{
		if (!isset($this->tablesCache[$name])) {
			$cache = Cache::getCacheAdapter();
			$this->tablesCache[$name] = $cache->get('model.db.tables.' . $name, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($name) {
				$item->tag('db.table');
				$item->expiresAfter(3600 * 24 * 30);
				return $this->makeTable($name);
			});

			Cache::registerInvalidation('tag', ['db.table']);
		}

		return $this->tablesCache[$name];
	}

	/**
	 * @param string $name
	 * @return Table
	 */
	private function makeTable(string $name): Table
	{
		$existingTables = $this->getExistingTables();
		if (!in_array($name, $existingTables))
			throw new \Exception('Table ' . $name . ' does not exist');

		// Create Table object
		$table = new Table($name);

		// Load and parse the columns of the table
		$explainQuery = $this->db->query('EXPLAIN `' . $name . '`');
		$columns = [];
		foreach ($explainQuery as $c) {
			if (preg_match('/^enum\(.+\).*$/i', $c['Type'])) {
				$type = 'enum';
				$values = explode(',', preg_replace('/^enum\((.+)\).*$/i', '$1', $c['Type']));
				foreach ($values as &$v)
					$v = preg_replace('/^\'(.+)\'$/i', '$1', $v);
				unset($v);
				$length = $values;
			} elseif (preg_match('/^.+\([0-9,]+\).*$/i', $c['Type'])) {
				$type = strtolower(preg_replace('/^(.+)\([0-9,]+\).*$/i', '\\1', $c['Type']));
				$length = preg_replace('/^.+\(([0-9,]+)\).*$/i', '\\1', $c['Type']);
			} else {
				$type = $c['Type'];
				$length = false;
			}

			$columns[$c['Field']] = [
				'type' => $type,
				'length' => $length,
				'null' => $c['Null'] === 'YES',
				'key' => $c['Key'],
				'default' => $c['Default'],
				'extra' => $c['Extra'],
				'foreign_keys' => [],
			];
		}

		$table->loadColumns($columns);

		// Load and parse the foreign keys
		$foreign_keys = [];
		$create = $this->db->query('SHOW CREATE TABLE `' . $name . '`')->fetch();
		if ($create and isset($create['Create Table'])) {
			$create = $create['Create Table'];
			$righe_query = explode("\n", str_replace("\r", '', $create));
			foreach ($righe_query as $r) {
				$r = trim($r);
				if (str_starts_with($r, 'CONSTRAINT')) {
					preg_match_all('/CONSTRAINT `(.+?)` FOREIGN KEY \(`(.+?)`\) REFERENCES `(.+?)` \(`(.+?)`\)/i', $r, $matches, PREG_SET_ORDER);

					$fk = $matches[0];
					if (count($fk) !== 5)
						continue;

					$on_update = 'RESTRICT';
					if (preg_match('/ON UPDATE (NOT NULL|DELETE|CASCADE|NO ACTION)/i', $r, $upd_match))
						$on_update = $upd_match[1];

					$on_delete = 'RESTRICT';
					if (preg_match('/ON DELETE (NOT NULL|DELETE|CASCADE|NO ACTION)/i', $r, $del_match))
						$on_delete = $del_match[1];

					$foreign_keys[] = [
						'name' => $fk[1],
						'column' => $fk[2],
						'ref_table' => $fk[3],
						'ref_column' => $fk[4],
						'update' => $on_update,
						'delete' => $on_delete,
					];
				}
			}
		}

		$table->loadForeignKeys($foreign_keys);

		return $table;
	}

	/**
	 * @return array
	 */
	private function getExistingTables(): array
	{
		if (!isset($this->tablesList)) {
			$this->tablesList = [];
			$tables = $this->db->query('SHOW TABLES');
			foreach ($tables as $row)
				$this->tablesList[] = $row[array_keys($row)[0]];
		}

		return $this->tablesList;
	}
}
