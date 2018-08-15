<?php
/**
 * @NAME: View
 * @DESC: View prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.03
 * DATE: 2018-08-15
 * добавлена магия __set - теперь в хелпере можно устанавливать значения для родительского View
 *
 * 1.02
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 */

class ViewHelper
{
	protected $view;

	function __construct()
	{
	}

	public function __get($name)
	{
		if (isset($this->view))
		{
			return $this->view->$name;
		}
	}

	public function __set($name, $value)
	{
		if (isset($this->view))
		{
			$this->view->$name = $value;
		}
	}

	public function __call($method_name, $args)
	{//теперь можно вызывать хелперы из других хелперов
		if (isset($this->view))
		{
			return $this->view->__call($method_name, $args);
		}
	}

	public function setView(View $view)
	{
		$this->view = $view;
		return $this;
	}
}
