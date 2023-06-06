<?php
namespace YAVPL;

/**
 * @NAME: Trait: Simple Dictionary Controller
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2016 - Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
*/

/** CHANGELOG
 *
 * 1.01
 * DATE: 2017-05-30
 * добавлена поддержка внешних ключей (пример - catalog/propvalues)
 *
 * 1.00
 * DATE: 2016-10-31
 * собран прототип
*/

interface iSimpleDictionaryController
{
	public function getModelInstance();

	public function getResourceForModification();
}

trait SimpleDictionaryController
{
	public function getListParams()
	{
		return [];
	}

	public function getList()
	{
		$this->isAJAX();
		$model_instance = $this->getModelInstance();
		print json_encode([
			'status'	=> 'OK',
			'meta_data'	=> $model_instance->getMetaData(),
			'data'		=> $model_instance->getList($this->getListParams()),
		]);
	}

	public function saveRow()
	{
		$this->isAJAX();
		if (!$this->checkAccess($this->getResourceForModification()))
		{
			print json_encode(['status' => $this->message]);
			return;
		}
		$model_instance = $this->getModelInstance();
		$this->id = $this->getParam('id', 'integer');
		$this->info = $model_instance->getRow($this->id);//before change

		if ($this->info === false)
		{
			print json_encode(['status' => "Объект под номером {$this->id} не найден"]);
			return;
		}

		$new_info = [];
		foreach ($model_instance->getMetaData()['fields'] as $field => $field_info)
		{
			//da($field_info);
			$new_info[$field] = $this->getParam($field, $field_info['type']);
		}
		$new_info['id'] = $this->id;
		//da($new_info);

		$this->message = $model_instance->saveRow($new_info);
		$this->id = $model_instance->key_value;

		if ($this->message == '')
		{
			$this->info = $model_instance->getRow($this->id);//get it once again after changing
			print json_encode(['status' => 'OK', 'info' => $this->info]);
		}
		else
		{
			print json_encode(['status' => $this->message]);
		}
	}

	public function deleteRow()
	{
		$this->isAJAX();
		if (!$this->checkAccess($this->getResourceForModification()))
		{
			print json_encode(['status' => $this->message]);
			return;
		}
		$model_instance = $this->getModelInstance();
		$this->id = $this->getParam('id', 'integer');
		$this->info = $model_instance->getRow($this->id);

		if ($this->info === false)
		{
			print json_encode(['status' => "Объект под номером {$this->id} не найден"]);
			return;
		}
		$this->message = $model_instance->deleteRow($this->id);
		if ($this->message == '')
		{
			print json_encode(['status' => 'OK', 'info' => $this->info]);
		}
		else
		{
			print json_encode(['status' => $this->message]);
		}
	}

	public function updateField()
	{
		$this->isAJAX();
		if (!$this->checkAccess($this->getResourceForModification()))
		{
			print json_encode(['status' => $this->message]);
			return;
		}
		$model_instance = $this->getModelInstance();
		$this->id = $this->getParam('id', 'integer');
		$this->info = $model_instance->getRow($this->id);//before change

		if ($this->info === false)
		{
			print json_encode(['status' => "Объект под номером {$this->id} не найден"]);
			return;
		}
		$field = $this->getParam('field', 'string');
		$field_type = $model_instance->getMetaData()['fields'][$field]['type'];
		$this->message = $model_instance->updateField(
			$this->id,
			$field,
			$this->getParam('value', $field_type));

		if ($this->message == '')
		{
			$this->info = $model_instance->getRow($this->id);//get it once again after changing
			print json_encode(['status' => 'OK', 'info' => $this->info]);
		}
		else
		{
			print json_encode(['status' => $this->message]);
		}
	}
}