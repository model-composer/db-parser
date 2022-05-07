<?php namespace Model\DbParser;

class Table
{
	public array $primary = [];
	public array $columns = [];

	/**
	 * Table constructor.
	 *
	 * @param string $name
	 */
	public function __construct(public string $name)
	{
	}

	/**
	 * @param array $columns
	 * @return void
	 */
	public function loadColumns(array $columns): void
	{
		foreach ($columns as $k => $c) {
			if ($c['key'] === 'PRI')
				$this->primary[] = $k;

			$c['real'] = true; // To distinguish plugins fake columns

			$this->columns[$k] = $c;
		}

		$this->primary = array_unique($this->primary);
	}

	/**
	 * @param array $foreign_keys
	 * @return void
	 */
	public function loadForeignKeys(array $foreign_keys): void
	{
		foreach ($foreign_keys as $fk) {
			if(!isset($this->columns[$fk['column']]))
				throw new \Exception('Something is wrong, column ' . $fk['column'] . ', declared in foreign key ' . $fk['name'] . ' doesn\'t seem to exist!');

			$this->columns[$fk['column']]['foreign_keys'][] = $fk;
		}
	}
}
