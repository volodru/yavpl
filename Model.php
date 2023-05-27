<?php
namespace YAVPL;

/**
 * @NAME: Model
 * @DESC: Model prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.03
 * DATE: 2016-10-14
 * добавлен RegisterHelper, теперь помощники могут быть и у модели.
 *
 * 1.02
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 */

/*
 * Модель.
 *
 * Каждая модель (напрямую или через базовую модель проекта MainModel) в проекте является наследником
 * данного класса Model.
 *
 * Все модели проекта называются
 * 		class {NAME}Model extends Model
 * Все модели лежат в папке /models
 * Все субмодели проекта называются
 * 		class {MAIN_MODEL_NAME}_{SUBMODEL_NAME}Model extends Model
 * Все субмодели лежат в папке /models/{MAIN_MODEL_NAME}/, причем имя папки строго lowcase!
 * Пример:
 * 	папка models:
 * 		файл adplus.php - главная модель AdplusModel
 * 	папка models/adplus
 * 		файл prizes.php - субмодель Adplus_PrizesModel
 *
 * В проекте рекомендуется создать модель MainModel extends Model
 * и в ее конструкторе создавать соединение с базой, в ней делать
 * методы для логгирования, обработки ошибок и т.п., т.к. эти методы
 * всегда специфичны для проекта. Все остальные модели проекта
 * наследовать от MainModel.
 *
 *
 * Концепция подмоделей (или субмоделей)
 * ::::::::::::::::::::
 * В конструкторе главной модели заполнить массив имен используемых подмоделей
 * $this->__sub_models = array('brands', 'articles', ....);
 *
 * В главной модели становится доступной конструкция
 * $this->brands, $this->articles и т.д.
 * Конструктор подмодели вызывается при первом упоминании имени через __get(), т.е. при первом упоминании
 * подмодели загружается файл с исходником (из подкаталога strtolower{Mainmodel})
 * и делается new Model\Mainmodel\Submodel().
 *
 * Фактически, при этом конструктор подмодели исключается из процесса, т.к.
 * в него невозможно передать параметры и ХЗ когда он вообще запустится.
 * В подмодели стоит выносить простые вещи, типа наследников SimpleDictionaryModel
 * Плюс этого подхода - подмодели инициируются (и вообще грузится исходник) только
 * по мере необходимости.
 *

EXAMPLE:
class CompetitorsModel extends Model
{
	function __construct()
	{
		parent::__construct();
		//автозагрузка
		$this->__sub_models = array('brands');
	 }
 }
 **/

class Model
{
	protected $__sub_models = [];
	private $__sub_models_cache = [];
	private $__methods = [];
	/* ! public $db = null; - коннектор к главной базе проекта. заполняется магией __get*/

	protected $log = [];


	public 	function __construct()
	{
	}

/** устанавливает shared переменную для триггеров сохраняющих логи
 * надо делать именно от той модели, с которой делается загрузка
 */
	public function setGlobalDescription($description): void
	{
		$this->db->exec("SELECT set_var('description', '{$description}')");
	}

	/*
	protected function connectToMainDB()
	{

		global $application;
		return $this->db = $application->getMainDBConnector();

		//return null; //override
	}*/

/** Поле __sub_models - READ ONLY
 */
	public function getSubModelList(): array
	{
		return $this->__sub_models;
	}

	public function getBasicModel(string $name): Model
	{
		global $application;
		return $application->getBasicModel($name);
	}

	public function __get(string $name)//: mixed
	{
		global $application;
		if ($name == 'db')
		{
			//return $this->connectToMainDB();
			return $this->db = $application->getMainDBConnector();
		}
		elseif (isset($this->__sub_models_cache[$name]))
		{
			//da('IN __sub_models_cache');
			return $this->__sub_models_cache[$name];
		}
		elseif (in_array($name, $this->__sub_models))
		{
			//DEBUG
			//da('MODEL->__get submodels'); da(get_class($this).'--'.$name);//FOR DEBUG
			//END DEBUG

			$matches = [];
			if (preg_match("/^Models\\\\(.+)/", get_class($this), $matches))
			{
				//da($matches);//FOR DEBUG
				$s = "Models\\{$matches[1]}\\".ucfirst($name);
			}
			$this->__sub_models_cache[$name] = new $s();
			$this->__sub_models_cache[$name]->__parent = $this;
			return $this->__sub_models_cache[$name];
		}
		$this->$name = $application->getBasicModel($name);
		if (isset($this->$name))
		{
			return $this->$name;
		}
		else
		{
			//da('__get model '.$name);
			return;//null
		}
/*в наследнике делать что-то вроде:
	$result = parent::__get($name);
	if (isset($result)) {return $result;}
*/
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 */
	public function __call($method_name, $args)//: mixed
	{
		if (isset($this->__methods[$method_name]))
		{
			$helper = $this->__methods[$method_name];
			return call_user_func_array([$helper, $method_name], $args);
		}
		else
		{
			//da('__get model '.$method_name);
			sendBugReport("__call(".get_class($this)."->{$method_name})", 'called undefined MODEL method');
			return null;
		}
	}

	public function registerHelper($helper_class_name): Model
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}

	public function getLog($delimeter = CRLF): string
	{
		return join($delimeter, $this->log);
	}

/** Возвращает лог в виде массива строк
 * шаблон паблик Морозов :(
 */
	public function getRawLog(): array
	{
		return $this->log;
	}

/** Очищает лог до []
 */

	public function clearLog(): void
	{
		$this->log = [];
	}
}