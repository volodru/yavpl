<?php //YAVPL API
namespace YAVPL;
/** Контроллер по-умолчанию.
 * Наследники делают бизнес методы и заполняют в них переменную $result.
 * Остальное делаем тут.
 * status - OK все хорошо, иначе статус в соответствии с бизнес логикой, http response status отдается http_response_code
 * бизнес-логика не должна отдавать, что-то кроме http_response_code==200.
 * кроме случая - неправильный URL и контроллер действительно не найден - 404
 */

class ControllerAPI extends Controller
{
/** Переменная, в которую набирается результат работы.
 * Она будет выведена в конце в виде JSON*/
	public $result = [];

	public function __destruct()
	{
		$this->result['status'] ??= 'OK';//состояние по бизнес логике
		$this->result['http_response_code'] ??= 200;//состояние по HTTP протоколу

		header("Content-type: application/json");
		http_response_code($this->result['http_response_code']);
		print json_encode($this->result);
		parent::__destruct();
	}

	public function error($message, $status = 'ERROR', $http_response_code = 200)
	{
		$this->result['message'] = $message;
		$this->result['status'] = $status;
		$this->result['http_response_code'] = $http_response_code;
		exit();
	}

	public function defaultMethod($method_name)
	{
		$this->error("Method [{$method_name}] not implemented. RTFM.", 'Not Implemented', 501);
	}
}
