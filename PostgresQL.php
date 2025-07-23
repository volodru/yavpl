<?php
namespace YAVPL;
/**
 * Интерфейс к таблицам постгреса
*/

/**

--Т.к. у веб проектов нет доступа к некоторым структурам данных, то оборачиваем запросы к information_schema в функции.

DROP  FUNCTION public.getForeignKeys(text, text);
DROP TYPE public.foreign_keys_list;

CREATE TYPE public.foreign_keys_list AS
(
	table_schema text,
	constraint_name text,
	table_name text,
	column_name text,
	foreign_table_schema text,
	foreign_table_name text,
	foreign_column_name text
);

ALTER TYPE public.foreign_keys_list OWNER TO postgres;
GRANT USAGE ON TYPE public.foreign_keys_list TO PUBLIC;
GRANT USAGE ON TYPE public.foreign_keys_list TO portal;
GRANT USAGE ON TYPE public.foreign_keys_list TO postgres;

CREATE OR REPLACE FUNCTION public.getForeignKeys(
	src_schema_name text, src_table_name text)
    RETURNS SETOF foreign_keys_list
    LANGUAGE 'sql'
    SECURITY DEFINER
    COST 100
    VOLATILE PARALLEL UNSAFE
AS $BODY$
SELECT
	tc.table_schema,
	tc.constraint_name, tc.table_name, kcu.column_name,
	ccu.table_schema AS foreign_table_schema,
	ccu.table_name AS foreign_table_name,
	ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu USING (constraint_schema, constraint_name)
JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name)
WHERE
	constraint_type = 'FOREIGN KEY' AND
	tc.constraint_schema = src_schema_name AND
	tc.table_name = src_table_name
$BODY$;

ALTER FUNCTION public.getForeignKeys(text, text)  OWNER TO postgres;
GRANT EXECUTE ON FUNCTION public.getforeignkeys(text, text) TO PUBLIC;
GRANT EXECUTE ON FUNCTION public.getforeignkeys(text, text) TO portal;
GRANT EXECUTE ON FUNCTION public.getforeignkeys(text, text) TO postgres;

CREATE OR REPLACE FUNCTION public.getForeignKeysBackLinks(
		src_schema_name text, src_table_name text)
    RETURNS SETOF foreign_keys_list
    LANGUAGE 'sql'
    SECURITY DEFINER
    COST 100
    VOLATILE PARALLEL UNSAFE
AS $BODY$
SELECT
	tc.table_schema,
	tc.constraint_name,
	tc.table_name,
	kcu.column_name,
	ccu.table_schema AS foreign_table_schema,
	ccu.table_name AS foreign_table_name,
	ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu USING (constraint_schema, constraint_name)
JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name)
WHERE
	constraint_type = 'FOREIGN KEY' AND
	ccu.table_schema = src_schema_name AND
	ccu.table_name = src_table_name
ORDER BY tc.table_schema, tc.table_name
$BODY$;

ALTER FUNCTION public.getForeignKeysBackLinks(text, text)  OWNER TO postgres;
GRANT EXECUTE ON FUNCTION public.getForeignKeysBackLinks(text, text) TO PUBLIC;
GRANT EXECUTE ON FUNCTION public.getForeignKeysBackLinks(text, text) TO portal;
GRANT EXECUTE ON FUNCTION public.getForeignKeysBackLinks(text, text) TO postgres;

*/

class PostgresQL extends Model
{
	public function getSchemasList(): array
	{
		return $this->db->exec("
SELECT nspname AS schema_name, description
FROM pg_catalog.pg_namespace AS ns
JOIN pg_catalog.pg_description AS pgd ON (pgd.objoid=ns.oid)
WHERE nspname !~ '^(pg_|sql_)'
ORDER BY nspname")->fetchAll();
	}

	private function clearTableName(string $table_name = ''): string
	{
		$table_name = preg_replace("/[^0-9A-Za-z_\.]/" , '', $table_name);
		return $table_name;
	}

	public function getTablesList(?string $schema_name = '', ?bool $fill_ext_data = true): array
	{
		//т.к. $schema_name передаем прямо в URL - то убираем из неё всё, кроме допустимого
		$schema_name = $this->clearTableName($schema_name);

		$where = ["relkind = 'r'"];
		if ($schema_name != '')
		{
			$where[] = "ns.nspname = '{$schema_name}'";
		}
		else
		{
			$where[] = "ns.nspname !~ '^(pg_|sql_)'";
		}
		$list = $this->db->exec("
SELECT
	ns.nspname || '.' ||relname AS key,
	ns.nspname AS schema_name,
	relname AS table_name,
	obj_description(c.oid) AS description
FROM pg_class AS c
JOIN pg_catalog.pg_namespace AS ns ON (c.relnamespace = ns.oid)
WHERE ".join(' AND ', $where)."
ORDER BY schema_name, relname")->fetchAll('key');
		if ($fill_ext_data)
		{
			foreach (array_keys($list) as $key)
			{
				$list[$key]['fkeys'] = $this->getForeignKeys($key);
				$list[$key]['back_fkeys'] = $this->getForeignKeysBackLinks($key);
				$list[$key]['fields'] = $this->getFieldsList($key);
			}
		}
		return $list;
	}

	public function getTableInfo(string $schema_name, string $rel_name): ?array
	{
		return $this->db->exec("
SELECT
	ns.nspname AS schema_name,
	relname AS table_name,
	obj_description(c.oid) AS description
FROM pg_class AS c
JOIN pg_catalog.pg_namespace AS ns ON (c.relnamespace = ns.oid)
WHERE relkind = 'r' AND ns.nspname = $1 AND relname = $2", $schema_name, $rel_name)->fetchRow();

	}

	private function checkTableName(string $schema_name, ?string $table_name = ''): array
	{
		$schema_name = $this->clearTableName($schema_name);
		$table_name  = $this->clearTableName($table_name);
		if ($table_name == '')
		{
			$a = explode('.', $schema_name);
			if (count($a) == 1)
			{
				$a[0] = 'public';
				$a[1] = $schema_name;
			}
			return $a;
		}
		else
		{
			return [$schema_name, $table_name];
		}
	}

	public function getFieldsList(string $schema_name, ?string $table_name = ''): array
	{//http://stackoverflow.com/questions/343138/retrieving-comments-from-a-postgresql-db
		list($schema_name, $table_name) = $this->checkTableName($schema_name, $table_name);
		return $this->db->exec("
SELECT c.column_name AS field_name, pgd.description, c.udt_name,
	c.column_default, c.is_nullable, c.data_type --, c.*
FROM information_schema.columns AS c
LEFT OUTER JOIN pg_catalog.pg_statio_all_tables AS st ON (c.table_schema=st.schemaname AND c.table_name=st.relname)
LEFT OUTER JOIN pg_catalog.pg_description AS pgd ON (pgd.objsubid=c.ordinal_position AND pgd.objoid=st.relid)
WHERE c.table_schema = $1 AND c.table_name = $2
ORDER BY c.ordinal_position, c.column_name", $schema_name, $table_name)->fetchAll('field_name');
	}

	public function getForeignKeys(string $schema_name, ?string $table_name = ''): array
	{
		list($schema_name, $table_name) = $this->checkTableName($schema_name, $table_name);
		return $this->db->exec("SELECT * FROM public.getForeignKeys($1, $2)", $schema_name, $table_name)->fetchAll('column_name');
	}

	public function getForeignKeysBackLinks(string $schema_name, ?string $table_name = ''): array
	{
		list($schema_name, $table_name) = $this->checkTableName($schema_name, $table_name);
		return $this->db->exec("SELECT * FROM public.getForeignKeysBackLinks($1, $2)", $schema_name, $table_name)->fetchAll();
/*
		return $this->db->exec("
SELECT
	tc.table_schema,
	tc.constraint_name,
	tc.table_name,
	kcu.column_name,
	ccu.table_schema AS foreign_table_schema,
	ccu.table_name AS foreign_table_name,
	ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
	JOIN information_schema.key_column_usage AS kcu USING (constraint_schema, constraint_name)
	JOIN information_schema.constraint_column_usage AS ccu USING (constraint_schema, constraint_name)
WHERE
	constraint_type = 'FOREIGN KEY' AND
	ccu.table_schema = $1 AND
	ccu.table_name = $2
ORDER BY tc.table_schema, tc.table_name", $schema_name, $table_name)->fetchAll();*/
	}

	public function getSequencesList(string $schema_name): array
	{
		return $this->db->exec("SELECT * FROM information_schema.sequences WHERE '{$schema_name}' = '' OR sequence_schema = $1", $schema_name)->fetchAll();
	}
}