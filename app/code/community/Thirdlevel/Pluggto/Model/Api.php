<?php

class Thirdlevel_Pluggto_Model_Api extends Mage_Core_Model_Abstract
{
	protected function _construct()
	{
		$this->_init('pluggto/api');
	}

	// get pluggto call model
	public static function getCall()
	{
		return Mage::getModel('pluggto/call');
	}

	// get pluggto timestamp
	public function getTime()
	{
		return $this->getCall()->doCall('time', null, null, 'GET', false);
	}

	public function get($resource, $data = null, $type = null, $auth = false)
	{
		return $this->getCall()->doCall($resource, $data, $type, 'GET', $auth);
	}

	public function getTableIndex($resource)
	{
		return $this->getCall()->doCall($resource, null, null, 'GET', true);
	}

	public function put($resource, $data)
	{
		return $this->getCall()->doCall($resource, $data, null, 'PUT', true);
	}

	public function post($resource, $data)
	{
		return $this->getCall()->doCall($resource, $data, null, 'POST', true);
	}

	public function del($resource)
	{
		return $this->getCall()->doCall($resource, null, null, 'DELETE', true);
	}
}