<?php

declare(strict_types=1);

namespace Joomla\CMS\Form {
    class Form
    {
        public array $values = [];

        public array $fields = [];

        public function getValue($name, $group = null, $default = null)
        {
            return $this->values[$group][$name] ?? $default;
        }

        public function getField($name, $group = null)
        {
            return $this->fields[$group][$name] ?? false;
        }
    }

    abstract class FormField
    {
        protected $type = '';

        protected $element;

        protected $form;

        protected $id = '';

        protected $fieldname = '';

        protected $group = '';

        protected $value = '';

        public function setForm(Form $form)
        {
            $this->form = $form;

            return $this;
        }

        public function setup(\SimpleXMLElement $element, $value, $group = null)
        {
            $this->element = $element;
            $this->value = $value;
            $this->group = (string) $group;
            $this->fieldname = (string) $element['name'];
            $this->id = 'jform_'.$this->group.'_'.$this->fieldname;

            return true;
        }

        public function __get($name)
        {
            return $this->$name ?? null;
        }

        protected function getInput()
        {
            return '<select id="'.$this->id.'"></select>';
        }

        protected function getOptions()
        {
            return [];
        }
    }
}

namespace Joomla\CMS\Form\Field {
    use Joomla\CMS\Form\FormField;

    abstract class ListField extends FormField
    {
        protected function getOptions()
        {
            return [];
        }
    }
}
