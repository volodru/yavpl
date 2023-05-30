<?php
namespace YAVPL;

/**
 * @NAME: DbPgSingleton
 * @VERSION: 1.00
 * @DATE: 2016-11-01
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2016 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.00
 * класс выделен в отдельный файл
 */

class DbPgSingleton //singleton in the global scope
{
	protected static $instance;

	public static function getInstance(array $host_params) {// Возвращает единственный экземпляр класса
		if (is_null(self::$instance))
		{
			self::$instance = new DbPg($host_params);
		}
		return self::$instance;
	}
}
