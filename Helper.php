<?php
namespace YAVPL;
/**
 * @NAME: Helper
 * @DESC: Helper prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.04
 * DATE: 2019-10-05
 * переименован из ViewHelper в просто Helper, чтобы сделать универсальный помощник для представлений, моделей и контроллеров
 *
 * 1.03
 * DATE: 2018-08-15
 * добавлена магия __set - теперь в хелпере можно устанавливать значения для владельца
 *
 * 1.02
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 */

class Helper
{
	protected $__owner;

	public function __construct()
	{
	}

	public function getOwner()
	{
		return $this->__owner;
	}

	public function __get($name)
	{
		if (isset($this->__owner))
		{
			return $this->__owner->$name;
		}
	}

	public function __set($name, $value)
	{
		if (isset($this->__owner))
		{
			$this->__owner->$name = $value;
		}
	}

	public function __call($method_name, $args)
	{//можно вызывать хелперы из других хелперов
		if (isset($this->__owner))
		{
			return $this->__owner->__call($method_name, $args);
		}
	}

	public function setOwner($owner)
	{
		$this->__owner = $owner;
		return $this;
	}

/**
 * Регистрирует помощник.
 * Инициируется помощник, из него берутся все методы и складываются в кучку.
 * После этого все методы класса могут пользоваться методами помощника как своими собственными.
 * Типичный помощник: helpers/html.php
 * Помощник сам может использовать методы вызвавшего его представления через магию __get()
 */
	public static function registerHelper($helper_class_name, $owner)//class
	{//if register more than one helper with the same method - method of the last helper will be called. I guess. :)
		$name = '\\Helpers\\'.$helper_class_name;
		//da("making new Helper class {$name}");
		$helper = new $name();
		$helper->setOwner($owner);
		$methods = [];
		foreach (get_class_methods($name) as $method)
		{
			$methods[$method] = $helper;
		}
		return $methods;
	}
}
