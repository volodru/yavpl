<?php
declare(strict_types=1);
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
 * Каждая модель (напрямую или через базовую модель проекта \Models\Main) в проекте является наследником
 * данного класса \YAVPL\Model.
 *
 * Все модели проекта называются
 * 		class {NAME} extends \YAVPL\Model
 * Все модели лежат в папке /models
 * Все субмодели проекта называются
 * 		namespace Models\{MAIN_MODEL_NAME};
 * 		class {SUBMODEL_NAME} extends (\Models\Main или \YAVPL\Model)
 * Все субмодели лежат в папке /models/{MAIN_MODEL_NAME}/, причем имя папки строго lowcase!
 * Пример:
 * 	папка models:
 * 		файл adplus.php - главная модель \Models\Adplus
 * 	папка models/adplus
 * 		файл prizes.php - субмодель \Models\Adplus\Prizes
 *
 * Концепция подмоделей (или субмоделей) *
 * ::::::::::::::::::::
 * В конструкторе главной модели заполнить массив имен используемых подмоделей
 * $this->__sub_models = ['brands', 'articles', ......];
 *
 * В главной модели становится доступной конструкция
 * $this->brands, $this->articles и т.д.
 * Конструктор подмодели вызывается при первом упоминании имени через __get(), т.е. при первом упоминании
 * подмодели загружается файл с исходником (из подкаталога strtolower{Mainmodel})
 * и делается new Models\MainModel\Submodel().
 *
 * Фактически, при этом конструктор подмодели исключается из процесса, т.к.
 * в него невозможно передать параметры и ХЗ когда он вообще запустится.
 * В подмодели стоит выносить простые вещи, типа наследников DbTable
 * Плюс этого подхода - подмодели инициируются (и вообще грузится исходник) только
 * по мере необходимости.
 *
 * Вся эта концепция - костыль чтобы сделать вложенные классы, которые не поддерживаются самим ПХП (чтобы с локальной видимостью).
 *

EXAMPLE:
class Competitors extends Model
{
	function __construct()
	{
		parent::__construct();
		//автозагрузка
		$this->__sub_models = ['brands'];
	 }
 }
 **/

#[\AllowDynamicProperties]
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
	public function setGlobalDescription(string $description): void
	{
		$this->db->exec("SELECT set_var('description', $1)", $description);
	}

/** Поле __sub_models - READ ONLY
 * volod, 2024-11-19 proposed for deletion - т.к. вроде оно никому не надо
 */
	public function getSubModelList(): array
	{
		return $this->__sub_models;
	}

/** ЭКСПЕРИМЕНТ от 2025-05-07!
 * Возможный вызов
 * use \Models\Catalog;
 * Catalog::Articles()->getList(['limit' => 10]);
 * либо
 * \Models\Catalog::Articles()->getList(['limit' => 10]);
 */
	public static function __callStatic($name, $arguments)
	{
		$s = get_called_class().'\\'.$name;
		return new $s(...$arguments);
	}


	public function __get(string $name): mixed
	{
		global $application;
		if ($name == 'db')
		{//главная СУБД проекта. все модели имеют доступ к главной базе по переменной $this->db
			return $this->db = $application->getMainDBConnector();
		}

		$sub_model_name = strtolower($name);
		if (isset($this->__sub_models_cache[$sub_model_name]))
		{//кеш подмоделей, если вызываем их не первый раз
			return $this->__sub_models_cache[$sub_model_name];
		}
		elseif (in_array($sub_model_name, array_map(fn($name) => strtolower($name), $this->__sub_models)))
		{//подмодели - инициируем и кладем ссылку на класс в кеш
			$model_name = get_class($this).'\\'.$name;
			return $this->__sub_models_cache[$sub_model_name] = new $model_name();
		}


//---------- Базовые модели подразделов - практически глобальные переменные
		$this->$name = $application->getBasicModel($name);
		if (isset($this->$name))
		{
			return $this->$name;
		}
		else
		{//если нет - пусть дальше разбираются наследники
			//da('__get model '.$name);
			return null;//null
		}

		return null;
/** в наследнике делать что-то вроде:
	$result = parent::__get($name);
	if (isset($result)) {return $result;}
*/
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 */
	public function __call(string $method_name, array $args)//: mixed
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

/** Регистрируем помощник.
 * см. Helper::registerHelper
 * @var string $helper_class_name - название класса-хелпера
 * @return Model
 */
	public function registerHelper(string $helper_class_name): Model
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}

/** Вовращает лог в виде строки через разделитель
 * @var string $delimeter
 * @return string
 */
	public function getLog(string $delimeter = CRLF): string
	{
		return join($delimeter, $this->log);
	}

/** Возвращает лог в виде массива строк
 * шаблон паблик Морозов :(
 * @return array
 */
	public function getRawLog(): array
	{
		return $this->log;
	}

/** Очищает накопленный лог до []
 * @return void
 */
	public function clearLog(): void
	{
		$this->log = [];
	}
}