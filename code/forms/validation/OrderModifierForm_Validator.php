<?php


class OrderModifierForm_Validator extends RequiredFields{

	function php($data){
		$this->form->saveDataToSession();
		return parent::php($data);
	}

}