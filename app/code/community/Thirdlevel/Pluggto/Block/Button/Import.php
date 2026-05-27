<?php

class Thirdlevel_Pluggto_Block_Button_Import extends Mage_Adminhtml_Block_System_Config_Form_Field
{
	/**
	 * Import static blocks
	 *
	 * @param Varien_Data_Form_Element_Abstract $element
	 * @return String
	 */
	protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
	{
		$elementOriginalData = $element->getOriginalData();

		if (isset($elementOriginalData['process'])) {
			$name = $elementOriginalData['process'];
		} else {
			return '<div>Action was not specified</div>';
		}

		$buttonSuffix = '';
		if (isset($elementOriginalData['label']))
			$buttonSuffix = ' ' . $elementOriginalData['label'];

		$url = $this->getUrl('*/pluggto_sync/' . $name);

		$html = $this->getLayout()->createBlock('adminhtml/widget_button')
			->setType('button')
			->setClass('import-cms')
			->setLabel($buttonSuffix)
			->setOnClick("setLocation('$url')")
			->toHtml();

		return $html;
	}
}