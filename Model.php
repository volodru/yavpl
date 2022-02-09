<?php
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
 * добавлен RegisterHelper, теперь помощники могут быть и у представления и у модели.
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
 * и делается new {Mainmodel}_{Submodel}Model().
 *
 * Фактически, при этом конструктор подмодели исключается из процесса, т.к.
 * в него невозможно передать параметры и ХЗ когда он вообще запустится.
 * В подмодели стоит выносить простые вещи, типа наследников SimpleDictionaryModel
 * Плюс этого подхода - подмодели инициируются (и вообще грузится исходник) только
 * по мере необходимости.
 *
 * Если нужно явно инициировать подмодель через ее конструктор , то надо
 * явно создать подмодель со всем параметрами конструктора
 * $sub_model = new Mainmodel_SubmodelModel($arg1, ..., $argN)
 * и потом обязательно вызвать setSubModel($sub_model), чтобы при
 * упоминании подмодели она не создалась через __get() с конструктором без параметров.
 *
 * Минус - нужно не забыть подгрузить подмодель и как-то позаботиться о повторных вызовах,
 * если вызывать не из конструктора.

EXAMPLE:
class CompetitorsModel extends Model
{
	function __construct()
	{
		parent::__construct();
		//автозагрузка
		$this->__sub_models = array('brands');
		//конструктор - не самое лучшее место, т.к. подмодель грузится ВСЕГДА
		$this->setSubModel(new Competitors_BrandsModel(array(1,2,3,4)));
	 }
 }
--------------------------------------------------------------
 *
 * Свойства по умолчанию:
 *
 * Если в проекте используются свойства, то надо не забывать вызывать родительский __get(),
 * т.к. подмодели работают именно через него.
 *
 **/

class Model
{
	private $__sub_models_cache = [];
	protected $__sub_models = [];
	private $__methods = [];

 	function __construct()
	{
	}

	public function setSubModel(Model $sub_model)
	{
		$matches = [];
		preg_match("/^(.+)Model/", get_class($this), $matches);//главная модель
		$this_class_name = $matches[1];
//@TODO - поправить для работы с вложенными субмоделями, буде таковые когда-нибудь понадобятся.
//!!это не будет работать на вложенных подмоделях!!!
		preg_match("/^{$this_class_name}_(.+)Model/", get_class($sub_model), $matches);//имя подмодели
		$sub_model->__parent = $this;
		return $this->__sub_models_cache[$matches[1]] = $sub_model;
	}

	public function __get($name)
	{
		if (isset($this->__sub_models_cache[$name]))
		{
			return $this->__sub_models_cache[$name];
		}
		elseif (in_array($name, $this->__sub_models))
		{
			$matches = [];
			preg_match("/^(.+)Model/", get_class($this), $matches);
			$s = $matches[1].'_'.ucfirst($name).'Model';
			$this->__sub_models_cache[$name] = new $s();
			$this->__sub_models_cache[$name]->__parent = $this;
			return $this->__sub_models_cache[$name];
		}
		else
		{
			//da('__get model '.$name);
			return;//null
		}
		//в наследнике делать что-то вроде:
		//$result = parent::__get($name);
		//if (isset($result)) return $result;
	}

/**
 * Обеспечение работы помощников.
 * После регистрации помощника, все его методы доступны в представлении как свои собственные.
 * Используется магия __call()
 */
	public function __call($method_name, $args)
	{
		if (isset($this->__methods[$method_name]))
		{
			$helper = $this->__methods[$method_name];
			return call_user_func_array([$helper, $method_name], $args);
		}
		else
		{
			//da('__get model '.$method_name);
			return null;
		}
	}

	public function registerHelper($helper_class_name)//class
	{
		$this->__methods = array_merge($this->__methods, Helper::registerHelper($helper_class_name, $this));
		return $this;
	}

}