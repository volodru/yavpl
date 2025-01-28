<?php //YAVPL API
namespace YAVPL;
/**
 * @NAME: Controller
 * @DESC: CLI Controller
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2024- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * DATE: 2024-02-08
 * выделен в отдельный файл для сохранения общности с API
*/

/** Контроллер CLI.
 */

class ControllerCLI extends Controller
{
/** Для консольного режима разбираем командную строку на параметры вида key=value
* Всё, что не в этом формате - игнорим.
* Параметры заполняем в глобальный массив через setParam.
*/
	public function __construct()
	{
		parent::__construct();
		foreach ($_SERVER['argv'] ?? [] as $param)
		{
			$matches = [];
			if (preg_match("/^(.+?)\=(.+)$/", $param, $matches))
			{
				$this->setParam($matches[1], $matches[2]);
			}
		}
	}
}