<?php
/**
 * @NAME: Test
 * @DESC: Test prototype
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.02
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.01
 * DATE: 2013-07-16
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 * добавлена функция assertNull
 */

class Test
{
	public $class;
	public $testedMethod = '';
	public $assertsCount = 0;
	protected $controller;

	function __construct($controller)
	{
		$this->controller = $controller;
	}

	public function startTest($method)
	{
		$this->testedMethod = $method;
		$this->failures = [];
		$this->assertsCount = 0;
	}

	public function endTest()
	{
		return [
			'testedMethod' => $this->testedMethod,
			'assertsCount' => $this->assertsCount,
			'failures' => $this->failures,
		];
	}

	private function __assert($state, $msg)
	{
		if (!$state)
		{
			$trace = debug_backtrace(false);
			$this->failures[$trace[1]['line']] = $msg;
		}
		return $state;
	}

	public function assertNull($actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert(is_null($actual), (isset($msg) ? $msg : "Null expected, but some value passed"));
	}

	public function assertTrue($actual, $msg = null)
	{
		$this->assertsCount++;
		if (!$this->__assert(is_bool($actual), "Passed value [$actual] is not a boolean type")) return;
		return $this->__assert($actual, (isset($msg) ? $msg : "TRUE expected, but FALSE passed"));
	}

	public function assertFalse($actual, $msg = null)
	{
		$this->assertsCount++;
		if (!$this->__assert(is_bool($actual), "Passed value [$actual] is not a boolean type")) return;
		return $this->__assert(!$actual, (isset($msg) ? $msg : "FALSE expected, but TRUE passed"));
	}

	public function assertEquals($expected, $actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert($expected == $actual, (isset($msg) ? $msg : "[$expected] expected, but [$actual] passed"));
	}

	public function assertGreaterThan($expected, $actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert($expected < $actual, (isset($msg) ? $msg : "Greater than [$expected] expected, but [$actual] passed"));
	}

	public function assertGreaterThanOrEqual($expected, $actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert($expected <= $actual, (isset($msg) ? $msg : "Greater than [$expected] expected, but [$actual] passed"));
	}

	public function assertLessThan($expected, $actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert($expected > $actual, (isset($msg) ? $msg : "Less than [$expected] expected, but [$actual] passed"));
	}

	public function assertLessThanOrEqual($expected, $actual, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert($expected >= $actual, (isset($msg) ? $msg : "Less than [$expected] expected, but [$actual] passed"));
	}

	public function assertArrayHasKey($needle, $array, $msg = null)
	{
		$this->assertsCount++;
		if (!$this->__assert(is_array($array), "Passed haystack is not an array")) return false;
		return $this->__assert(isset($array[$needle]), (isset($msg) ? $msg : "[$needle] is not exist in array"));
	}

	public function assertEmpty($array, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert(count($array) == 0, (isset($msg) ? $msg : "Array is not empty"));
	}

	public function assertNotEmpty($array, $msg = null)
	{
		$this->assertsCount++;
		return $this->__assert(count($array) != 0, (isset($msg) ? $msg : "Array is empty"));
	}

	public function assertAlmostEqual($expected, $actual, $percentage, $msg = null)
	{
		$this->assertsCount++;
		if (!$this->__assert(is_numeric($expected), "Expected [$expected] is not a numeric type")) return false;
		if (!$this->__assert(is_numeric($actual), "Passed value [$actual] is not a numeric type")) return false;
		if (!$this->__assert(is_numeric($percentage), "Given percentage [$percentage] is not a numeric type")) return false;
		return $this->__assert($actual > ($expected * (100 - $percentage) / 100 ) && $actual < ($expected * (100 + $percentage) / 100),
			(isset($msg) ? $msg : "Expected [$expected] with $percentage% accuracy, but [$actual] passed"));
	}
}